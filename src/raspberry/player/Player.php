<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\entity\Human;
use raspberry\entity\Skin;
use raspberry\entity\damage\DamageSource;
use raspberry\entity\object\ItemEntity;
use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use raspberry\inventory\ArmorInventory;
use raspberry\inventory\Inventory;
use raspberry\inventory\PlayerInventory;

use raspberry\item\Item;
use raspberry\item\ItemSerializer;
use raspberry\item\enchantment\Enchantment;

use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\DoubleTag;
use raspberry\nbt\tag\FloatTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\StringTag;
use raspberry\nbt\NbtException;

use raspberry\block\BlockIds;
use raspberry\block\BlockCollision;
use raspberry\block\BlockProperties;

use raspberry\command\CommandSender;

use raspberry\math\Vector3;

use raspberry\network\mcpe\auth\AuthenticatedLogin;
use raspberry\network\mcpe\NetworkSession;
use raspberry\network\mcpe\protocol\ActorEventPacket;
use raspberry\network\mcpe\protocol\AnimatePacket;
use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\MobEffectPacket;
use raspberry\network\mcpe\protocol\SetTitlePacket;
use raspberry\network\mcpe\protocol\TextPacket;
use raspberry\network\mcpe\protocol\ToastRequestPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\permission\PermissionLevel;
use raspberry\permission\PermissibleTrait;
use raspberry\permission\PermissionManager;

use raspberry\utils\Uuid;

use raspberry\world\Chunk;
use raspberry\world\World;
use raspberry\world\WorldManager;

use function array_map;
use function sin;
use function ceil;
use function cos;
use function deg2rad;
use function floor;
use function is_int;
use function intdiv;
use function max;
use function min;
use function mt_rand;
use function microtime;
use function sqrt;

class Player extends Human implements CommandSender{
    use PermissibleTrait;

    /** players this far below the bottom of the world (Y -104) are hurt by the void */
    public const VOID_DEPTH = 40;

    /** 4 health (2 hearts) every half second */
    public const VOID_DAMAGE = 4.0;

    private const VOID_DAMAGE_TICKS = 10;

    public const MAX_XP_LEVEL = 24791;

    /** a head inside a full block takes this much damage every this many ticks */
    private const SUFFOCATION_DAMAGE = 1.0;
    private const SUFFOCATION_TICKS = 10;

    private const FIRE_DAMAGE_TICKS = 10;

    /** falls up to this many blocks do no damage */
    public const SAFE_FALL_DISTANCE = 3.0;

    public const ENDER_CHEST_SIZE = 27;

    /** the UI inventory holds the item on the cursor and what the creative menu or crafting makes */
    public const UI_SIZE = 54;
    public const UI_CURSOR = 0;
    public const UI_CREATED_OUTPUT = 50;

    /** first slot of the 2x2 crafting grid of the inventory screen, and of the crafting table's 3x3 grid */
    public const UI_CRAFTING_SMALL = 28;
    public const UI_CRAFTING_LARGE = 32;

    /** the input slots of the work blocks */
    public const UI_ANVIL_INPUT = 1;
    public const UI_ANVIL_MATERIAL = 2;
    public const UI_STONECUTTER_INPUT = 3;
    public const UI_LOOM_INPUT = 9;
    public const UI_LOOM_DYE = 10;
    public const UI_LOOM_PATTERN = 11;
    public const UI_CARTOGRAPHY_INPUT = 12;
    public const UI_CARTOGRAPHY_ADDITIONAL = 13;
    public const UI_ENCHANTING_INPUT = 14;
    public const UI_ENCHANTING_LAPIS = 15;
    public const UI_GRINDSTONE_INPUT = 16;
    public const UI_GRINDSTONE_ADDITIONAL = 17;
    public const UI_BEACON_PAYMENT = 27;
    public const UI_SMITHING_INPUT = 51;
    public const UI_SMITHING_MATERIAL = 52;
    public const UI_SMITHING_TEMPLATE = 53;

    private NetworkSession $session;

    private AuthenticatedLogin $identity;

    private GameMode $gameMode;

    private bool $spawned = false;

    private bool $flying = false;

    private bool $dead = false;

    private bool $swimming = false;

    private bool $sprinting = false;

    private bool $sneaking = false;

    /** blocks fallen since the player last stood on something */
    private float $fallDistance = 0.0;

    private int $lastVoidDamageTick = 0;

    private int $lastSuffocationTick = 0;

    private int $lastFireDamageTick = 0;

    private int $xpLevel = 0;

    /** how far to the next level, 0 to 1 */
    private float $xpProgress = 0.0;

    /** picks the enchanting table's offers, changes after every enchant */
    private int $enchantmentSeed = 0;

    /** translation key of the death message for the next death, e.g. "death.attack.outOfWorld" */
    private ?string $deathCause = null;

    /** personal spawn point (/spawnpoint), null to use the world spawn */
    private ?Vector3 $spawnPosition = null;

    private ?string $spawnWorldName = null;

    /** when the player first joined */
    private int $firstPlayed;

    private PlayerInventory $inventory;

    private ArmorInventory $armorInventory;

    private Inventory $offHandInventory;

    private Inventory $enderInventory;

    private Inventory $uiInventory;

    private RecipeBook $recipeBook;

    private BlockBreaker $blockBreaker;

    private BlockPlacer $blockPlacer;

    private ContainerOpener $containerOpener;

    private ItemUser $itemUser;

    public function __construct(World $world, Vector3 $position, NetworkSession $session, AuthenticatedLogin $identity, GameMode $gameMode, Skin $skin){
        $this->session = $session;
        $this->identity = $identity;
        $this->gameMode = $gameMode;
        $this->flying = $gameMode->forcesFlying();
        $this->firstPlayed = (int) (microtime(true) * 1000);
        $this->inventory = new PlayerInventory();
        $this->armorInventory = new ArmorInventory();
        $this->offHandInventory = new Inventory(1);
        $this->enderInventory = new Inventory(self::ENDER_CHEST_SIZE);
        $this->uiInventory = new Inventory(self::UI_SIZE);
        $this->recipeBook = new RecipeBook($this, $session->getServer()->getCraftingManager());
        $this->blockBreaker = new BlockBreaker($this);
        $this->blockPlacer = new BlockPlacer($this);
        $this->containerOpener = new ContainerOpener($this);
        $this->itemUser = new ItemUser($this);

        foreach([$this->inventory, $this->armorInventory, $this->offHandInventory, $this->uiInventory] as $inventory){
            $inventory->addListener($this->recipeBook);
        }

        parent::__construct($world, $position, $skin, Uuid::fromXuid($identity->xuid));
    }

    /**
     * @return array{World, Vector3, float, float}|null
     */
    public static function getSavedLocation(CompoundTag $data, WorldManager $worldManager) : ?array{
        $position = $data->getListTag("Pos");
        $rotation = $data->getListTag("Rotation");
        $world = $worldManager->getWorld($data->getString("World", ""));

        if($world === null || $position === null || $position->count() !== 3 || $position->getTagType() !== DoubleTag::TAG_DOUBLE){
            return null;
        }

        [$x, $y, $z] = $position->getAllValues();
        [$yaw, $pitch] = $rotation !== null && $rotation->count() === 2 && $rotation->getTagType() === FloatTag::TAG_FLOAT ? $rotation->getAllValues() : [0.0, 0.0];

        return [$world, new Vector3($x, $y, $z), $yaw, $pitch];
    }

    public function loadData(CompoundTag $data) : void{
        $this->gameMode = GameMode::tryFrom($data->getInt("PlayerGameType", $this->gameMode->value)) ?? $this->gameMode;

        foreach($data->getListTag("ActiveEffects")?->getValue() ?? [] as $tag){
            $effect = $tag instanceof CompoundTag ? Effect::tryFrom($tag->getByte("Id", 0) ?? 0) : null;

            if($effect !== null && !$effect->isInstant() && ($duration = $tag->getInt("Duration", 0) ?? 0) > 0){
                $this->effects->add(new EffectInstance($effect, $duration, $tag->getByte("Amplifier", 0) ?? 0, $tag->getByte("Ambient", 0) === 1, $tag->getByte("ShowParticles", 1) !== 0), true);
            }
        }

        $this->flying = $this->gameMode->forcesFlying() || ($this->gameMode->allowsFlying() && $data->getByte("Flying", 0) === 1);

        $this->health = max(0.0, min($data->getFloat("Health", $this->maxHealth), $this->maxHealth));
        $this->absorption = max(0.0, $data->getFloat("AbsorptionAmount", 0.0));
        $this->food = max(0, min($data->getInt("FoodLevel", self::MAX_FOOD), self::MAX_FOOD));
        $this->saturation = max(0.0, min($data->getFloat("FoodSaturationLevel", self::DEFAULT_SATURATION), $this->food));
        $this->exhaustion = max(0.0, min($data->getFloat("FoodExhaustionLevel", 0.0), self::MAX_EXHAUSTION));
        $this->xpProgress = max(0.0, min($data->getFloat("XpP", 0.0) ?? 0.0, 1.0));
        $this->xpLevel = max(0, min($data->getInt("XpLevel", 0) ?? 0, self::MAX_XP_LEVEL));
        $this->enchantmentSeed = $data->getInt("EnchantmentSeed") ?? mt_rand();

        if($this->health <= 0){
            $this->resetVitals();
        }

        $spawnWorld = $data->getString("SpawnWorld");

        if($spawnWorld !== null){
            $this->spawnWorldName = $spawnWorld;
            $this->spawnPosition = new Vector3($data->getInt("SpawnX", 0) + 0.5, (float) $data->getInt("SpawnY", 0), $data->getInt("SpawnZ", 0) + 0.5);
        }

        $this->firstPlayed = $data->getLong("FirstPlayed", $this->firstPlayed);
        $this->foodTickTimer = max(1, min($data->getInt("FoodTickTimer", 1), 80));
        $this->fallDistance = max(0.0, $data->getFloat("FallDistance", 0.0));

        $this->inventory->setContents(self::readItems($data->getListTag("Inventory"), true, PlayerInventory::SIZE));
        $this->armorInventory->setContents(self::readItems($data->getListTag("Armor"), false, 4));
        $this->offHandInventory->setContents(self::readItems($data->getListTag("Offhand"), false, 1));
        $this->enderInventory->setContents(self::readItems($data->getListTag("EnderChestInventory"), true, self::ENDER_CHEST_SIZE));

        $recipes = $data->getListTag("UnlockedRecipes");

        if($recipes !== null && $recipes->getTagType() === StringTag::TAG_STRING){
            $this->recipeBook->load($recipes->getAllValues());
        }

        $heldSlot = $data->getInt("SelectedInventorySlot", 0);

        if($heldSlot >= 0 && $heldSlot < PlayerInventory::HOTBAR_SIZE){
            $this->inventory->setHeldSlot($heldSlot);
        }
    }

    public function saveData() : CompoundTag{
        [$world, $position] = $this->dead ? $this->getSpawnLocation() : [$this->world, $this->position];

        $data = (new CompoundTag())
            ->setInt("DataVersion", 1)
            ->setString("Name", $this->getName())
            ->setLong("FirstPlayed", $this->firstPlayed)
            ->setLong("LastPlayed", (int) (microtime(true) * 1000))
            ->setString("World", $world->getName())
            ->setTag("Pos", new ListTag([new DoubleTag($position->x), new DoubleTag($position->y), new DoubleTag($position->z)]))
            ->setTag("Rotation", new ListTag([new FloatTag($this->yaw), new FloatTag($this->pitch)]))
            ->setInt("PlayerGameType", $this->gameMode->value)
            ->setByte("Flying", $this->flying ? 1 : 0)
            ->setFloat("Health", $this->dead ? $this->maxHealth : $this->health)
            ->setFloat("AbsorptionAmount", $this->dead ? 0.0 : $this->absorption)
            ->setInt("FoodLevel", $this->dead ? self::MAX_FOOD : $this->food)
            ->setFloat("FoodSaturationLevel", $this->dead ? self::DEFAULT_SATURATION : $this->saturation)
            ->setFloat("FoodExhaustionLevel", $this->dead ? 0.0 : $this->exhaustion)
            ->setFloat("XpP", $this->xpProgress)
            ->setInt("XpLevel", $this->xpLevel)
            ->setInt("EnchantmentSeed", $this->enchantmentSeed)
            ->setInt("FoodTickTimer", $this->dead ? 1 : $this->foodTickTimer)
            ->setFloat("FallDistance", $this->dead ? 0.0 : $this->fallDistance)
            ->setInt("SelectedInventorySlot", $this->inventory->getHeldSlot())
            ->setTag("Inventory", self::writeItems($this->inventory, true))
            ->setTag("Armor", self::writeItems($this->armorInventory, false))
            ->setTag("Offhand", self::writeItems($this->offHandInventory, false))
            ->setTag("EnderChestInventory", self::writeItems($this->enderInventory, true))
            ->setTag("UnlockedRecipes", new ListTag(array_map(fn(string $id) => new StringTag($id), $this->recipeBook->getUnlocked()), StringTag::TAG_STRING))
            ->setTag("ActiveEffects", $this->saveEffects());

        if($this->spawnPosition !== null && $this->spawnWorldName !== null){
            $data->setString("SpawnWorld", $this->spawnWorldName)
                ->setInt("SpawnX", $this->spawnPosition->getFloorX())
                ->setInt("SpawnY", $this->spawnPosition->getFloorY())
                ->setInt("SpawnZ", $this->spawnPosition->getFloorZ());
        }

        return $data;
    }

    private function saveEffects() : ListTag{
        $list = [];

        foreach($this->dead ? [] : $this->effects->all() as $effect){
            $list[] = (new CompoundTag())
                ->setByte("Id", $effect->getEffect()->value)
                ->setByte("Amplifier", $effect->getAmplifier())
                ->setInt("Duration", $effect->getDuration())
                ->setByte("Ambient", $effect->isAmbient() ? 1 : 0)
                ->setByte("ShowParticles", $effect->isVisible() ? 1 : 0);
        }

        return new ListTag($list, CompoundTag::TAG_COMPOUND);
    }

    public function getName() : string{
        return $this->identity->username;
    }

    public function getXuid() : string{
        return $this->identity->xuid;
    }

    public function getNameTag() : string{
        return $this->getName();
    }

    public function getDeviceOs() : int{
        $deviceOs = $this->identity->clientData->DeviceOS ?? -1;
        return is_int($deviceOs) ? $deviceOs : -1;
    }

    public function getIdentity() : AuthenticatedLogin{
        return $this->identity;
    }

    private static function writeItems(Inventory $inventory, bool $withSlots) : ListTag{
        $list = new ListTag([], CompoundTag::TAG_COMPOUND);

        foreach($inventory->getContents(!$withSlots) as $slot => $item){
            $list->push(ItemSerializer::serialize($item, $withSlots ? $slot : null));
        }

        return $list;
    }

    /**
     * @return array<int, Item>
     */
    private static function readItems(?ListTag $list, bool $withSlots, int $size) : array{
        $items = [];

        foreach($list?->getValue() ?? [] as $index => $entry){
            if(!$entry instanceof CompoundTag){
                continue;
            }

            try{
                $slot = $withSlots ? ($entry->getByte("Slot", -1) ?? -1) : $index;
                $item = ItemSerializer::deserialize($entry);
            }catch(NbtException){
                continue;
            }

            if($slot >= 0 && $slot < $size && !$item->isNull()){
                $items[$slot] = $item;
            }
        }

        return $items;
    }

    public function getInventory() : PlayerInventory{
        return $this->inventory;
    }

    public function getArmorInventory() : ArmorInventory{
        return $this->armorInventory;
    }

    public function getOffHandInventory() : Inventory{
        return $this->offHandInventory;
    }

    public function getEnderInventory() : Inventory{
        return $this->enderInventory;
    }

    public function getUIInventory() : Inventory{
        return $this->uiInventory;
    }

    public function getRecipeBook() : RecipeBook{
        return $this->recipeBook;
    }

    public function getBlockBreaker() : BlockBreaker{
        return $this->blockBreaker;
    }

    public function getBlockPlacer() : BlockPlacer{
        return $this->blockPlacer;
    }

    public function getContainerOpener() : ContainerOpener{
        return $this->containerOpener;
    }

    public function getItemUser() : ItemUser{
        return $this->itemUser;
    }

    public function getXpLevel() : int{
        return $this->xpLevel;
    }

    public function getXpProgress() : float{
        return $this->xpProgress;
    }

    /**
     * Experience points needed to get from $level to the next level.
     */
    public static function getXpToNextLevel(int $level) : int{
        return match(true){
            $level >= 30 => 9 * $level - 158,
            $level >= 15 => 5 * $level - 38,
            default => 2 * $level + 7
        };
    }

    /**
     * Gives experience points, with the pickup sound, and the level up sound every 5 levels.
     */
    public function addXp(int $amount) : void{
        if($amount <= 0 || $this->xpLevel >= self::MAX_XP_LEVEL){
            return;
        }

        $oldLevel = $this->xpLevel;
        $points = $this->xpProgress * self::getXpToNextLevel($this->xpLevel) + $amount;

        while($this->xpLevel < self::MAX_XP_LEVEL && $points >= self::getXpToNextLevel($this->xpLevel)){
            $points -= self::getXpToNextLevel($this->xpLevel);
            ++$this->xpLevel;
        }

        $this->xpProgress = $this->xpLevel >= self::MAX_XP_LEVEL ? 0.0 : min($points / self::getXpToNextLevel($this->xpLevel), 1.0);

        $position = $this->getPosition();
        $this->session->sendDataPacket(LevelEventPacket::create(LevelEvent::SOUND_EXPERIENCE_ORB_PICKUP, $position->x, $position->y, $position->z));

        if(intdiv($this->xpLevel, 5) > intdiv($oldLevel, 5)){
            $this->session->sendDataPacket(LevelSoundEventPacket::create(LevelSoundEvent::LEVELUP, $position->x, $position->y, $position->z, 0x10000000 * intdiv(min(30, $this->xpLevel), 5)));
        }

        $this->session->sendAttributes();
    }

    /**
     * Takes levels for an enchant or an anvil, keeping the progress to the next level.
     */
    public function takeXpLevels(int $levels) : void{
        $this->xpLevel = max(0, $this->xpLevel - $levels);
        $this->session->sendAttributes();
    }

    public function getEnchantmentSeed() : int{
        return $this->enchantmentSeed;
    }

    public function resetEnchantmentSeed() : void{
        $this->enchantmentSeed = mt_rand();
    }

    public function moveUIItemsToInventory() : void{
        foreach($this->uiInventory->getContents() as $slot => $item){
            $this->uiInventory->clear($slot);

            foreach($this->inventory->addItem($item) as $leftover){
                $this->dropItem($leftover);
            }
        }
    }

    public function dropItem(Item $item) : ?ItemEntity{
        $yaw = deg2rad($this->yaw);
        $pitch = deg2rad($this->pitch);
        $speed = 0.3;

        $motion = new Vector3(-sin($yaw) * cos($pitch) * $speed, -sin($pitch) * $speed + 0.1, cos($yaw) * cos($pitch) * $speed);

        return $this->world->dropItem($this->getEyePosition()->add(0, -0.3, 0), $item, $motion, ItemEntity::THROWN_PICKUP_DELAY);
    }

    public function dropInventory() : void{
        $this->moveUIItemsToInventory();

        $items = [];

        foreach([$this->inventory, $this->armorInventory, $this->offHandInventory] as $inventory){
            $items = [...$items, ...$inventory->getContents()];
            $inventory->clearAll();
        }

        foreach($items as $item){
            if(!$item->hasEnchantment(Enchantment::VANISHING)){
                $this->world->dropItem($this->position, $item);
            }
        }
    }

    /**
     * Shows the item in the player's hand to everyone who can see them.
     */
    public function broadcastHeldItem() : void{
        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendHeldItem($this);
        }
    }

    public function swingArm(string $source) : void{
        $packet = AnimatePacket::create(AnimatePacket::ACTION_SWING_ARM, $this->getId(), $source);

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendDataPacket($packet);
        }
    }

    public function broadcastOffHandItem() : void{
        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendOffHandItem($this);
        }
    }

    /**
     * Shows the player's armor to everyone who can see them.
     */
    public function broadcastArmor() : void{
        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendArmor($this);
        }
    }

    public function getNetworkSession() : NetworkSession{
        return $this->session;
    }

    public function getGameMode() : GameMode{
        return $this->gameMode;
    }

    /**
     * Changes the game mode, tells the client and shows or hides the player for others (spectators
     * are only seen by other spectators).
     */
    public function setGameMode(GameMode $gameMode) : void{
        if($gameMode === $this->gameMode){
            return;
        }

        $this->gameMode = $gameMode;
        $this->flying = $gameMode->forcesFlying() || ($this->flying && $gameMode->allowsFlying());

        if($this->spawned){
            $this->session->sendGameMode();
            $this->updateVisibility();
        }
    }

    public function isFlying() : bool{
        return $this->flying;
    }

    /**
     * Starts or stops flying. Only works in a game mode that allows flying, and spectators cannot stop.
     *
     * @return bool false when the game mode does not allow it
     */
    public function setFlying(bool $flying) : bool{
        if(($flying && !$this->gameMode->allowsFlying()) || (!$flying && $this->gameMode->forcesFlying())){
            return false;
        }

        $this->flying = $flying;

        if($this->spawned){
            $this->session->sendAbilities();
        }

        return true;
    }

    /**
     * @internal
     */
    public function onClientFlightToggle(bool $flying) : void{
        if(($flying && !$this->gameMode->allowsFlying()) || (!$flying && $this->gameMode->forcesFlying())){
            $this->session->sendAbilities();
            return;
        }

        $this->flying = $flying;
    }

    public function isVisibleTo(Player $player) : bool{
        return !$this->dead && ($this->gameMode->isVisible() || !$player->getGameMode()->isVisible());
    }

    public function teleport(Vector3 $position, ?float $yaw = null, ?float $pitch = null, ?World $world = null) : void{
        $changesWorld = $world !== null && $world !== $this->world;

        if($changesWorld){
            foreach($this->world->getEntities() as $entity){
                $entity->despawnFrom($this);
            }
        }

        parent::teleport($position, $yaw, $pitch, $world);
        $this->fallDistance = 0.0;

        if($this->spawned){
            $this->session->onTeleport($changesWorld);
        }

        if($changesWorld){
            $this->updateVisibility();
        }
    }

    /**
     * The player's own spawn point, null when they use the world spawn.
     */
    public function getSpawnPosition() : ?Vector3{
        return $this->spawnPosition;
    }

    /**
     * @return array{World, Vector3}
     */
    public function getSpawnLocation() : array{
        $worldManager = $this->session->getServer()->getWorldManager();

        if($this->spawnPosition !== null && $this->spawnWorldName !== null){
            $world = $worldManager->getWorld($this->spawnWorldName);

            if($world !== null){
                return [$world, $this->spawnPosition];
            }
        }

        $world = $worldManager->getDefaultWorld();

        return [$world, $world->getSpawnPosition()];
    }

    public function setSpawnPosition(?Vector3 $position, ?World $world = null) : void{
        if($position === null){
            $this->spawnPosition = null;
            $this->spawnWorldName = null;
        }else{
            $this->spawnPosition = new Vector3($position->getFloorX() + 0.5, (float) $position->getFloorY(), $position->getFloorZ() + 0.5);
            $this->spawnWorldName = ($world ?? $this->world)->getName();
        }

        if($this->spawned){
            $this->session->sendSpawnPositions();
        }
    }

    public function isDead() : bool{
        return $this->dead;
    }

    public function killWithCause(string $cause) : void{
        if(!$this->dead && $this->isAlive()){
            $this->deathCause = $cause;
            $this->kill();
        }
    }

    protected function canBeDamagedBy(DamageSource $source) : bool{
        return !$this->dead && (!$this->gameMode->isInvulnerable() || $source->bypassesGameMode()) && parent::canBeDamagedBy($source);
    }

    public function onEffectAdded(EffectInstance $effect, ?EffectInstance $old) : void{
        parent::onEffectAdded($effect, $old);

        if($this->spawned){
            $this->session->sendEffect($old === null ? MobEffectPacket::EVENT_ADD : MobEffectPacket::EVENT_UPDATE, $effect);
            $this->broadcastEffectVisuals();
        }
    }

    public function onEffectRemoved(EffectInstance $effect) : void{
        parent::onEffectRemoved($effect);

        if($this->spawned){
            $this->session->sendEffect(MobEffectPacket::EVENT_REMOVE, $effect);
            $this->broadcastEffectVisuals();
        }
    }

    /**
     * Others see effect particles and invisibility through the player's metadata.
     */
    private function broadcastEffectVisuals() : void{
        $this->session->sendOwnMetadata();

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendHumanMetadata($this);
        }
    }

    protected function onDamaged(DamageSource $source) : void{
        $this->session->sendActorEvent($this, ActorEventPacket::HURT);

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendActorEvent($this, ActorEventPacket::HURT);
        }

        if($source->isReducedByArmor()){
            $this->exhaust(0.1);
        }
    }

    /**
     * Creative and spectator players never get hungry.
     */
    public function exhaust(float $amount) : void{
        if(!$this->gameMode->isInvulnerable()){
            parent::exhaust($amount);
        }
    }

    protected function onDeath() : void{
        $source = $this->lastDamageSource;
        $cause = $this->deathCause ?? $source?->getDeathMessageKey() ?? "death.attack.generic";
        $parameters = [$this->getName()];

        if($this->deathCause === null && ($attacker = $source?->getAttackerName()) !== null){
            $parameters[] = $attacker;
        }

        $this->deathCause = null;
        $this->dead = true;
        $this->effects->clear();

        if(!$this->session->getServer()->isKeepInventory()){
            $this->dropInventory();
            $this->xpLevel = 0;
            $this->xpProgress = 0.0;
        }

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendActorEvent($this, ActorEventPacket::DEATH);
        }

        $this->session->onDeath($cause, $parameters);
        $this->session->getServer()->onPlayerDeath($this, $cause, $parameters);
    }

    public function tick(int $currentTick) : void{
        if(!$this->spawned || $this->dead){
            return;
        }

        $this->effects->tick();

        if($this->dead){
            return;
        }

        if(!$this->gameMode->isInvulnerable()){
            $this->tickHunger($this->session->getServer()->getDifficulty());
        }

        $this->recipeBook->tick($currentTick);

        if($this->position->y < Chunk::MIN_Y - self::VOID_DEPTH && $currentTick - $this->lastVoidDamageTick >= self::VOID_DAMAGE_TICKS){
            $this->lastVoidDamageTick = $currentTick;
            $this->damage(new DamageSource(DamageSource::CAUSE_VOID, self::VOID_DAMAGE));
        }

        if($currentTick - $this->lastSuffocationTick >= self::SUFFOCATION_TICKS && $this->isHeadInBlock()){
            $this->lastSuffocationTick = $currentTick;
            $this->damage(new DamageSource(DamageSource::CAUSE_SUFFOCATION, self::SUFFOCATION_DAMAGE));
        }

        if($currentTick - $this->lastFireDamageTick >= self::FIRE_DAMAGE_TICKS && ($fire = $this->getFireAtFeet()) !== null){
            $this->lastFireDamageTick = $currentTick;
            $this->damage($fire);
        }

        $this->containerOpener->tick();
    }

    /**
     * Whether the player's eyes are inside a full block (spectators fly through blocks).
     */
    public function isHeadInBlock() : bool{
        if(!$this->gameMode->hasCollision()){
            return false;
        }

        $eyes = $this->getEyePosition();
        [$x, $y, $z] = [$eyes->getFloorX(), $eyes->getFloorY(), $eyes->getFloorZ()];

        return $y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && $this->world->isChunkLoaded($x >> 4, $z >> 4) && BlockCollision::isFullCube($this->world->getBlock($x, $y, $z));
    }

    /**
     * The damage from what the player stands in: a lit campfire burns, a soul campfire twice as
     * much, and a cauldron of lava like lava does.
     */
    private function getFireAtFeet() : ?DamageSource{
        [$x, $y, $z] = [$this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ()];

        if(!$this->gameMode->hasCollision() || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return null;
        }

        $block = $this->world->getBlock($x, $y, $z);

        return match(true){
            $block->getName() === BlockIds::CAMPFIRE && $block->getProperty(BlockProperties::EXTINGUISHED) === false => new DamageSource(DamageSource::CAUSE_FIRE, 1.0),
            $block->getName() === BlockIds::SOUL_CAMPFIRE && $block->getProperty(BlockProperties::EXTINGUISHED) === false => new DamageSource(DamageSource::CAUSE_FIRE, 2.0),
            $block->getName() === BlockIds::CAULDRON && $block->getProperty(BlockProperties::CAULDRON_LIQUID) === "lava" && $block->getProperty(BlockProperties::FILL_LEVEL) > 0 => new DamageSource(DamageSource::CAUSE_LAVA, 4.0),
            default => null
        };
    }

    /**
     * @internal
     *
     * @param bool $onGround the client stopped against something below it
     * @param bool $jumped   the client started a jump this tick
     */
    public function onClientMove(Vector3 $from, bool $onGround, bool $jumped) : void{
        $dx = $this->position->x - $from->x;
        $dz = $this->position->z - $from->z;
        $horizontal = sqrt($dx * $dx + $dz * $dz);

        if($this->swimming){
            $this->exhaust(0.01 * $horizontal);
        }elseif($this->sprinting){
            $this->exhaust(0.1 * $horizontal);
        }

        if($jumped){
            $this->exhaust($this->sprinting ? 0.2 : 0.05);
        }

        $this->updateFallDistance($this->position->y - $from->y, $onGround);
    }

    private function updateFallDistance(float $dy, bool $onGround) : void{
        if($this->flying || $this->swimming || $this->isInWater() || $this->effects->has(Effect::SLOW_FALLING) || $this->effects->has(Effect::LEVITATION)){
            $this->fallDistance = 0.0;
            return;
        }

        if($onGround){
            $this->fallDistance -= min($dy, 0.0);
            $damage = $this->fallDistance - self::SAFE_FALL_DISTANCE - $this->effects->getLevel(Effect::JUMP_BOOST);
            $this->fallDistance = 0.0;

            if($damage >= 0.5){
                $this->damage(new DamageSource(DamageSource::CAUSE_FALL, ceil($damage)));
            }
        }elseif($dy < 0){
            $this->fallDistance -= $dy;
        }else{
            $this->fallDistance = 0.0;
        }
    }

    /**
     * Water at the feet or the head breaks a fall.
     */
    public function isInWater() : bool{
        $x = $this->position->getFloorX();
        $z = $this->position->getFloorZ();

        foreach([$this->position->getFloorY(), (int) floor($this->position->y + $this->getEyeHeight())] as $y){
            if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y){
                continue;
            }

            $name = $this->world->getBlock($x, $y, $z)->getName();

            if($name === BlockIds::WATER || $name === BlockIds::FLOWING_WATER){
                return true;
            }
        }

        return false;
    }

    public function isSprinting() : bool{
        return $this->sprinting;
    }

    /**
     * @internal
     */
    public function setSprinting(bool $sprinting) : void{
        $this->sprinting = $sprinting;
    }

    public function isSneaking() : bool{
        return $this->sneaking;
    }

    /**
     * @internal
     */
    public function setSneaking(bool $sneaking) : void{
        $this->sneaking = $sneaking;
    }

    public function isSwimming() : bool{
        return $this->swimming;
    }

    /**
     * @internal
     */
    public function setSwimming(bool $swimming) : void{
        $this->swimming = $swimming;
    }

    public function getFallDistance() : float{
        return $this->fallDistance;
    }

    public function respawn() : void{
        if(!$this->dead){
            return;
        }

        $this->dead = false;
        $this->resetVitals();
        $this->flying = $this->gameMode->forcesFlying();
        $this->swimming = false;
        $this->sprinting = false;
        $this->lastDamageSource = null;

        [$world, $position] = $this->getSpawnLocation();

        $this->despawnFromAll();
        $this->teleport($position, null, null, $world);

        $this->session->onRespawn();
        $this->updateVisibility();
    }

    private function resetVitals() : void{
        $this->health = $this->maxHealth;
        $this->absorption = 0.0;
        $this->food = self::MAX_FOOD;
        $this->saturation = self::DEFAULT_SATURATION;
        $this->exhaustion = 0.0;
        $this->foodTickTimer = 1;
        $this->fallDistance = 0.0;
    }

    public function updateVisibility() : void{
        foreach($this->session->getServer()->getInGamePlayers() as $other){
            if($other === $this || $other->getWorld() !== $this->world){
                continue;
            }

            if($this->isVisibleTo($other)){
                $this->spawnTo($other);
            }else{
                $this->despawnFrom($other);
            }

            if($other->isVisibleTo($this)){
                $other->spawnTo($this);
            }else{
                $other->despawnFrom($this);
            }
        }
    }

    protected function onAttributesChanged() : void{
        if($this->spawned){
            $this->session->sendAttributes();
        }
    }

    /**
     * True once the client has finished loading and is in the world.
     */
    public function isSpawned() : bool{
        return $this->spawned;
    }

    /**
     * @internal
     */
    public function onSpawned() : void{
        $this->spawned = true;

        foreach($this->effects->all() as $effect){
            $this->session->sendEffect(MobEffectPacket::EVENT_ADD, $effect);
        }

        if($this->effects->all() !== []){
            $this->session->sendOwnMetadata();
        }
    }

    public function isOnline() : bool{
        return !$this->closed && $this->session->isConnected();
    }

    /**
     * Disconnects the player and shows $reason on their screen.
     */
    public function kick(string $reason) : void{
        $this->session->disconnect($reason);
    }

    /**
     * Shows a plain message in the player's chat.
     */
    public function sendMessage(string $message) : void{
        $this->session->sendMessage($message);
    }

    /**
     * @param list<string> $parameters
     */
    public function sendTranslation(string $message, array $parameters = []) : void{
        $this->session->sendTranslation($message, $parameters);
    }

    /**
     * Text above the hotbar, shown for a few seconds.
     */
    public function sendPopup(string $message) : void{
        $this->sendText(TextPacket::popup($message));
    }

    /**
     * Text in the middle of the screen, above the popup.
     */
    public function sendTip(string $message) : void{
        $this->sendText(TextPacket::tip($message));
    }

    /**
     * @param list<string> $parameters
     */
    public function sendJukeboxPopup(string $message, array $parameters = []) : void{
        $this->sendText(TextPacket::jukeboxPopup($message, $parameters));
    }

    /**
     * A chat message that still shows when the player has chat turned off.
     */
    public function sendSystemMessage(string $message) : void{
        $this->sendText(TextPacket::system($message));
    }

    /**
     * Shown as "<from> whispers to you: message".
     */
    public function sendWhisper(string $from, string $message) : void{
        $this->sendText(TextPacket::whisper($from, $message));
    }

    /**
     * Shown as "[from] message", like /say.
     */
    public function sendAnnouncement(string $from, string $message) : void{
        $this->sendText(TextPacket::announcement($from, $message));
    }

    /**
     * Shows a big title, with an optional subtitle under it. Times are in ticks (20 = 1 second);
     * leave them at -1 to keep the current ones (the client default is 10, 70, 20).
     */
    public function sendTitle(string $title, string $subtitle = "", int $fadeIn = -1, int $stay = -1, int $fadeOut = -1) : void{
        if($fadeIn >= 0 && $stay >= 0 && $fadeOut >= 0){
            $this->setTitleDuration($fadeIn, $stay, $fadeOut);
        }

        if($subtitle !== ""){
            $this->sendSubTitle($subtitle);
        }

        $this->session->sendDataPacket(SetTitlePacket::title($title));
    }

    /**
     * Sets the subtitle for the next title, or changes it while a title is showing.
     */
    public function sendSubTitle(string $subtitle) : void{
        $this->session->sendDataPacket(SetTitlePacket::subtitle($subtitle));
    }

    /**
     * Text above the hotbar, in the same place as a popup.
     */
    public function sendActionBarMessage(string $message) : void{
        $this->session->sendDataPacket(SetTitlePacket::actionBar($message));
    }

    /**
     * Times are in ticks (20 = 1 second).
     */
    public function setTitleDuration(int $fadeIn, int $stay, int $fadeOut) : void{
        $this->session->sendDataPacket(SetTitlePacket::durations($fadeIn, $stay, $fadeOut));
    }

    /**
     * Removes the title and subtitle from the screen.
     */
    public function removeTitles() : void{
        $this->session->sendDataPacket(SetTitlePacket::clear());
    }

    /**
     * Removes the title and puts the durations back to the client's defaults.
     */
    public function resetTitles() : void{
        $this->session->sendDataPacket(SetTitlePacket::reset());
    }

    /**
     * Slides a notification in at the top of the screen, like an achievement.
     */
    public function sendToast(string $title, string $body) : void{
        $this->session->sendDataPacket(ToastRequestPacket::create($title, $body));
    }

    private function sendText(TextPacket $packet) : void{
        if($packet->message !== ""){
            $this->session->sendDataPacket($packet);
        }
    }

    public function isOp() : bool{
        return $this->session->getServer()->getOperatorList()->isOperator($this->getXuid());
    }

    public function setOp(bool $op) : void{
        $operators = $this->session->getServer()->getOperatorList();

        if($op){
            $operators->add($this->getXuid(), $this->getName());
        }else{
            $operators->remove($this->getXuid());
        }

        $this->session->sendAvailableCommands();
        $this->session->sendAbilities();
    }

    public function getPermissionLevel() : PermissionLevel{
        return $this->isOp() ? PermissionLevel::OPERATOR : PermissionLevel::PUBLIC;
    }

    protected function getPermissionManager() : PermissionManager{
        return $this->session->getServer()->getPermissionManager();
    }

    public function close() : void{
        if($this->closed){
            return;
        }

        foreach($this->world->getEntities() as $entity){
            $entity->despawnFrom($this, false);
        }

        parent::close();
    }
}