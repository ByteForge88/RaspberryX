<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\entity\Entity;
use raspberry\entity\Human;
use raspberry\entity\Living;
use raspberry\entity\Skin;
use raspberry\entity\Vehicle;
use raspberry\entity\damage\DamageSource;
use raspberry\entity\object\ExperienceOrb;
use raspberry\entity\object\ItemEntity;
use raspberry\entity\object\Painting;
use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;
use raspberry\entity\projectile\FishingHook;

use raspberry\event\player\PlayerBedEnterEvent;
use raspberry\event\player\PlayerBedLeaveEvent;
use raspberry\event\player\PlayerDeathEvent;
use raspberry\event\player\PlayerExhaustEvent;
use raspberry\event\player\PlayerExperienceChangeEvent;
use raspberry\event\player\PlayerGameModeChangeEvent;
use raspberry\event\player\PlayerJumpEvent;
use raspberry\event\player\PlayerKickEvent;
use raspberry\event\player\PlayerRespawnEvent;
use raspberry\event\player\PlayerToggleFlightEvent;
use raspberry\event\player\PlayerToggleSneakEvent;
use raspberry\event\player\PlayerToggleSprintEvent;
use raspberry\event\player\PlayerToggleSwimEvent;

use raspberry\inventory\ArmorInventory;
use raspberry\inventory\Inventory;
use raspberry\inventory\PlayerInventory;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\ToolInfo;
use raspberry\item\ArmorInfo;
use raspberry\item\ItemSerializer;
use raspberry\item\enchantment\Enchantment;

use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\DoubleTag;
use raspberry\nbt\tag\FloatTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\StringTag;
use raspberry\nbt\NbtException;

use raspberry\block\Beds;
use raspberry\block\BigDripleaf;
use raspberry\block\BlockIds;
use raspberry\block\BlockCollision;
use raspberry\block\BlockProperties;
use raspberry\block\PointedDripstone;
use raspberry\block\growth\Farmland;

use raspberry\command\CommandFeedback;
use raspberry\command\CommandSender;

use raspberry\lang\Translation;

use raspberry\math\Vector3;

use raspberry\network\mcpe\auth\AuthenticatedLogin;
use raspberry\network\mcpe\NetworkSession;
use raspberry\network\mcpe\protocol\Packet;
use raspberry\network\mcpe\protocol\ActorEventPacket;
use raspberry\network\mcpe\protocol\AnimatePacket;
use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\MobEffectPacket;
use raspberry\network\mcpe\protocol\SetActorMotionPacket;
use raspberry\network\mcpe\protocol\SetTitlePacket;
use raspberry\network\mcpe\protocol\TextPacket;
use raspberry\network\mcpe\protocol\ToastRequestPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;
use raspberry\network\mcpe\protocol\types\ParticleEffect;

use raspberry\permission\PermissionLevel;
use raspberry\permission\PermissibleTrait;
use raspberry\permission\PermissionManager;

use raspberry\utils\UUID;

use raspberry\world\Chunk;
use raspberry\world\Dimension;
use raspberry\world\World;
use raspberry\world\Difficulty;
use raspberry\world\WorldManager;

use function abs;
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
    public const int VOID_DEPTH = 40;

    /** 4 health (2 hearts) every half second */
    public const float VOID_DAMAGE = 4.0;

    private const int VOID_DAMAGE_TICKS = 10;

    public const int MAX_XP_LEVEL = 24791;

    /** a head inside a full block takes this much damage every this many ticks */
    private const float SUFFOCATION_DAMAGE = 1.0;
    private const int SUFFOCATION_TICKS = 10;

    private const int FIRE_SECONDS = 8;
    private const int LAVA_SECONDS = 15;

    private const int TURTLE_BREATHING_TICKS = 200;

    /** falls up to this many blocks do no damage */
    public const float SAFE_FALL_DISTANCE = 3.0;

    /** blocks that catch a falling player, by climbing them or getting stuck in them */
    private const array BREAKS_FALL = [
        BlockIds::LADDER => true,
        BlockIds::VINE => true,
        BlockIds::SCAFFOLDING => true,
        BlockIds::WEEPING_VINES => true,
        BlockIds::TWISTING_VINES => true,
        BlockIds::CAVE_VINES => true,
        BlockIds::CAVE_VINES_BODY_WITH_BERRIES => true,
        BlockIds::CAVE_VINES_HEAD_WITH_BERRIES => true,
        BlockIds::WEB => true,
        BlockIds::SWEET_BERRY_BUSH => true,
        BlockIds::POWDER_SNOW => true
    ];

    /** how far a hit reaches from the eyes to the target's box, with some room for lag */
    public const float ATTACK_REACH = 3.0;
    public const float CREATIVE_ATTACK_REACH = 5.0;
    private const float REACH_LEEWAY = 1.5;

    /** a hit while falling does half as much again */
    public const float CRITICAL_MULTIPLIER = 1.5;

    /** extra push from a sprinting hit, and from each level of knockback */
    public const float SPRINT_KNOCKBACK = 0.5;

    /** what sharpness adds per level on Bedrock; smite and bane of arthropods add 2.5 */
    public const float SHARPNESS_DAMAGE = 1.25;
    public const float SMITE_DAMAGE = 2.5;

    public const int ENDER_CHEST_SIZE = 27;

    /** the UI inventory holds the item on the cursor and what the creative menu or crafting makes */
    public const int UI_SIZE = 54;
    public const int UI_CURSOR = 0;
    public const int UI_CREATED_OUTPUT = 50;

    /** first slot of the 2x2 crafting grid of the inventory screen, and of the crafting table's 3x3 grid */
    public const int UI_CRAFTING_SMALL = 28;
    public const int UI_CRAFTING_LARGE = 32;

    /** the input slots of the work blocks */
    public const int UI_ANVIL_INPUT = 1;
    public const int UI_ANVIL_MATERIAL = 2;
    public const int UI_STONECUTTER_INPUT = 3;
    public const int UI_LOOM_INPUT = 9;
    public const int UI_LOOM_DYE = 10;
    public const int UI_LOOM_PATTERN = 11;
    public const int UI_CARTOGRAPHY_INPUT = 12;
    public const int UI_CARTOGRAPHY_ADDITIONAL = 13;
    public const int UI_ENCHANTING_INPUT = 14;
    public const int UI_ENCHANTING_LAPIS = 15;
    public const int UI_GRINDSTONE_INPUT = 16;
    public const int UI_GRINDSTONE_ADDITIONAL = 17;
    public const int UI_BEACON_PAYMENT = 27;
    public const int UI_SMITHING_INPUT = 51;
    public const int UI_SMITHING_MATERIAL = 52;
    public const int UI_SMITHING_TEMPLATE = 53;

    private NetworkSession $session;

    private AuthenticatedLogin $identity;

    private GameMode $gameMode;

    private bool $spawned = false;

    private bool $flying = false;

    private bool $dead = false;

    private bool $swimming = false;

    private bool $sprinting = false;

    private bool $sneaking = false;

    /** what the interact button says on touch screens, for the mob the player looks at */
    private string $interactText = "";

    /** blocks fallen since the player last stood on something */
    private float $fallDistance = 0.0;

    /** whether the player walked since the surrounding blocks were last checked, sweet berry bushes only prick moving players */
    private bool $movedSinceContact = false;

    private bool $onGround = true;

    private int $lastVoidDamageTick = 0;

    private int $lastSuffocationTick = 0;

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

    private Dimension $spawnDimension = Dimension::OVERWORLD;

    /** whether the spawn point is a bed, which has to still be there when the player respawns */
    private bool $spawnAtBed = false;

    /** the head of the bed the player is asleep in, null while they are up */
    private ?Vector3 $sleepingAt = null;

    /** how long the player has been asleep, the night goes by after Sleeping::SKIP_TICKS */
    private int $sleepTicks = 0;

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

    private ?FishingHook $fishingHook = null;

    private ?Vehicle $vehicle = null;

    private ?MovementCheck $movementCheck = null;

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

        parent::__construct($world, $position, $skin, UUID::fromXuid($identity->xuid));
    }

    /**
     * @return array{World, Vector3, float, float}|null
     */
    public static function getSavedLocation(CompoundTag $data, WorldManager $worldManager) : ?array{
        $position = $data->getListTag("Pos");
        $rotation = $data->getListTag("Rotation");
        $world = $worldManager->getWorld($data->getString("World", ""));
        $dimension = Dimension::tryFrom($data->getInt("DimensionId", 0) ?? 0) ?? Dimension::OVERWORLD;

        if($world !== null){
            $world = $worldManager->getDimensionWorld($world, $dimension);
        }

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

            if($effect !== null && !$effect->isInstant() && (($duration = $tag->getInt("Duration", 0) ?? 0) > 0 || $duration === EffectInstance::INFINITE)){
                $this->effects->add(new EffectInstance($effect, $duration, $tag->getByte("Amplifier", 0) ?? 0, $tag->getByte("Ambient", 0) === 1, $tag->getByte("ShowParticles", 1) !== 0), true);
            }
        }

        $this->flying = $this->gameMode->forcesFlying() || ($this->gameMode->allowsFlying() && $data->getByte("Flying", 0) === 1);

        $this->health = max(0.0, min($data->getFloat("Health", $this->maxHealth), $this->maxHealth));
        $this->absorption = max(0.0, $data->getFloat("AbsorptionAmount", 0.0));
        $this->food = max(0, min($data->getInt("FoodLevel", self::MAX_FOOD), self::MAX_FOOD));
        $this->saturation = max(0.0, min($data->getFloat("FoodSaturationLevel", self::DEFAULT_SATURATION), $this->food));
        $this->exhaustion = max(0.0, min($data->getFloat("FoodExhaustionLevel", 0.0), self::MAX_EXHAUSTION));
        $this->air = max(0, min($data->getShort("Air", self::MAX_AIR) ?? self::MAX_AIR, self::MAX_AIR));
        $this->fireTicks = max(0, $data->getShort("Fire", 0) ?? 0);
        $this->xpProgress = max(0.0, min($data->getFloat("XpP", 0.0) ?? 0.0, 1.0));
        $this->xpLevel = max(0, min($data->getInt("XpLevel", 0) ?? 0, self::MAX_XP_LEVEL));
        $this->enchantmentSeed = $data->getInt("EnchantmentSeed") ?? mt_rand();

        if($this->health <= 0){
            $this->resetVitals();
        }

        $spawnWorld = $data->getString("SpawnWorld");

        if($spawnWorld !== null){
            $this->spawnWorldName = $spawnWorld;
            $this->spawnDimension = Dimension::tryFrom($data->getInt("SpawnDimension", 0) ?? 0) ?? Dimension::OVERWORLD;
            $this->spawnPosition = new Vector3($data->getInt("SpawnX", 0) + 0.5, (float) $data->getInt("SpawnY", 0), $data->getInt("SpawnZ", 0) + 0.5);
            $this->spawnAtBed = $data->getByte("SpawnBed", 0) === 1;
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
            ->setInt("DimensionId", $world->getDimension()->value)
            ->setTag("Pos", new ListTag([new DoubleTag($position->x), new DoubleTag($position->y), new DoubleTag($position->z)]))
            ->setTag("Rotation", new ListTag([new FloatTag($this->yaw), new FloatTag($this->pitch)]))
            ->setInt("PlayerGameType", $this->gameMode->value)
            ->setByte("Flying", $this->flying ? 1 : 0)
            ->setFloat("Health", $this->dead ? $this->maxHealth : $this->health)
            ->setFloat("AbsorptionAmount", $this->dead ? 0.0 : $this->absorption)
            ->setInt("FoodLevel", $this->dead ? self::MAX_FOOD : $this->food)
            ->setFloat("FoodSaturationLevel", $this->dead ? self::DEFAULT_SATURATION : $this->saturation)
            ->setFloat("FoodExhaustionLevel", $this->dead ? 0.0 : $this->exhaustion)
            ->setShort("Air", $this->dead ? self::MAX_AIR : $this->getAir())
            ->setShort("Fire", $this->dead ? 0 : min($this->fireTicks, 32767))
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
                ->setInt("SpawnDimension", $this->spawnDimension->value)
                ->setInt("SpawnX", $this->spawnPosition->getFloorX())
                ->setInt("SpawnY", $this->spawnPosition->getFloorY())
                ->setInt("SpawnZ", $this->spawnPosition->getFloorZ())
                ->setByte("SpawnBed", $this->spawnAtBed ? 1 : 0);
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

    public function getFishingHook() : ?FishingHook{
        return $this->fishingHook !== null && !$this->fishingHook->isClosed() ? $this->fishingHook : null;
    }

    public function setFishingHook(?FishingHook $hook) : void{
        $this->fishingHook = $hook;
    }

    public function getVehicle() : ?Vehicle{
        return $this->vehicle;
    }

    /**
     * @internal
     */
    public function setVehicle(?Vehicle $vehicle) : void{
        $this->vehicle = $vehicle;
        $this->fallDistance = 0.0;
        $this->broadcastEffectVisuals();
    }

    public function dismountVehicle() : void{
        $this->vehicle?->removePassenger($this);
    }

    /**
     * @internal
     */
    public function onVehicleMoved() : void{
        $this->fallDistance = 0.0;
    }

    public function getMovementCheck() : MovementCheck{
        return $this->movementCheck ??= new MovementCheck($this);
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

        $event = new PlayerExperienceChangeEvent($this, $amount);
        $event->call();
        $amount = $event->getAmount();

        if($event->isCancelled() || $amount <= 0){
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

    public function addXpLevels(int $levels) : void{
        if($levels <= 0){
            return;
        }

        $oldLevel = $this->xpLevel;
        $this->xpLevel = min(self::MAX_XP_LEVEL, $this->xpLevel + $levels);

        if(intdiv($this->xpLevel, 5) > intdiv($oldLevel, 5)){
            $position = $this->getPosition();
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

        $event = new PlayerGameModeChangeEvent($this, $gameMode);
        $event->call();

        if($event->isCancelled()){
            return;
        }

        $this->gameMode = $gameMode;
        $this->flying = $gameMode->forcesFlying() || ($this->flying && $gameMode->allowsFlying());
        $this->movementCheck?->reset();

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
        if($flying !== $this->flying && ($this->gameMode->allowsFlying() || !$flying)){
            $event = new PlayerToggleFlightEvent($this, $flying);
            $event->call();

            if($event->isCancelled()){
                $this->session->sendAbilities();
                return;
            }
        }

        if($flying && !$this->gameMode->allowsFlying()){
            if($this->session->getServer()->isCheatsAllowed()){
                $this->flying = true;
                return;
            }

            $this->kickForFlying();
            return;
        }

        if(!$flying && $this->gameMode->forcesFlying()){
            $this->session->sendAbilities();
            return;
        }

        $this->flying = $flying;
    }

    /**
     * @internal
     */
    public function kickForFlying() : void{
        $server = $this->session->getServer();
        $server->getLogger()->info($server->getLanguage()->translate("player.kicked.flying", ["player" => $this->getName()]));
        $this->kick($server->getLanguage()->translate("disconnect.flying"));
    }

    public function isVisibleTo(Player $player) : bool{
        return !$this->dead && ($this->gameMode->isVisible() || !$player->getGameMode()->isVisible());
    }

    protected function doTeleport(Vector3 $position, ?float $yaw, ?float $pitch, ?World $world) : void{
        $this->dismountVehicle();
        $changesWorld = $world !== null && $world !== $this->world;

        if($changesWorld){
            foreach($this->world->getEntities() as $entity){
                $entity->despawnFrom($this);
            }
        }

        $this->wake();

        parent::doTeleport($position, $yaw, $pitch, $world);
        $this->fallDistance = 0.0;
        $this->movementCheck?->reset();

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
                return [$worldManager->getDimensionWorld($world, $this->spawnDimension), $this->spawnPosition];
            }
        }

        $world = $worldManager->getDefaultWorld();

        return [$world, $world->getSpawnPosition()];
    }

    public function isSpawnAtBed() : bool{
        return $this->spawnAtBed;
    }

    /**
     * @param bool $bed
     */
    public function setSpawnPosition(?Vector3 $position, ?World $world = null, bool $bed = false) : void{
        if($position === null){
            $this->spawnPosition = null;
            $this->spawnWorldName = null;
            $this->spawnDimension = Dimension::OVERWORLD;
            $this->spawnAtBed = false;
        }else{
            $this->spawnPosition = new Vector3($position->getFloorX() + 0.5, (float) $position->getFloorY(), $position->getFloorZ() + 0.5);
            $this->spawnWorldName = ($world ?? $this->world)->getName();
            $this->spawnDimension = ($world ?? $this->world)->getDimension();
            $this->spawnAtBed = $bed;
        }

        if($this->spawned){
            $this->session->sendSpawnPositions();
        }
    }

    public function isSleeping() : bool{
        return $this->sleepingAt !== null;
    }

    public function getSleepingPosition() : ?Vector3{
        return $this->sleepingAt;
    }

    public function getSleepTicks() : int{
        return $this->sleepTicks;
    }

    public function sleep(Vector3 $bed) : bool{
        $this->wake();

        $bed = new Vector3($bed->getFloorX(), $bed->getFloorY(), $bed->getFloorZ());
        $event = new PlayerBedEnterEvent($this, $bed);
        $event->call();

        if($event->isCancelled() || $this->closed || $this->dead){
            return false;
        }

        $this->sleepingAt = $bed;
        $this->sleepTicks = 0;
        $this->sprinting = false;

        Beds::setOccupied($this->world, $bed->getFloorX(), $bed->getFloorY(), $bed->getFloorZ(), true);

        $this->move($bed->add(0.5, 0.5625, 0.5), $this->yaw, $this->pitch);
        $this->movementCheck?->reset();
        $this->broadcastEffectVisuals();

        $this->world->getSleeping()->onSleep($this);

        return true;
    }

    public function wake() : void{
        $bed = $this->sleepingAt;

        if($bed === null){
            return;
        }

        $this->sleepingAt = null;
        $this->sleepTicks = 0;

        Beds::setOccupied($this->world, $bed->getFloorX(), $bed->getFloorY(), $bed->getFloorZ(), false);

        $packet = AnimatePacket::create(AnimatePacket::ACTION_STOP_SLEEP, $this->getId());
        $this->session->sendDataPacket($packet);

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendDataPacket($packet);
        }

        $this->broadcastEffectVisuals();
        $this->movementCheck?->reset();

        (new PlayerBedLeaveEvent($this, $bed))->call();
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

    /**
     * Mobs hit harder in harder worlds: half and one more on easy, half again on hard, not at
     * all in peaceful.
     */
    protected function reduceDamage(DamageSource $source) : float{
        $attacker = $source->getAttacker();

        if($attacker instanceof Living && !$attacker instanceof self){
            $amount = $source->getAmount();

            $source->setAmount(match($this->session->getServer()->getDifficulty()){
                Difficulty::PEACEFUL => 0.0,
                Difficulty::EASY => min($amount / 2 + 1, $amount),
                Difficulty::NORMAL => $amount,
                Difficulty::HARD => $amount * 1.5
            });
        }

        return parent::reduceDamage($source);
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
        $this->wake();
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
        if($this->gameMode->isInvulnerable()){
            return;
        }

        if(PlayerExhaustEvent::hasHandlers()){
            $event = new PlayerExhaustEvent($this, $amount);
            $event->call();

            if($event->isCancelled()){
                return;
            }

            $amount = $event->getAmount();
        }

        parent::exhaust($amount);
    }

    protected function onDeath() : void{
        $source = $this->lastDamageSource;
        $cause = $this->deathCause ?? $source?->getDeathMessageKey() ?? "death.attack.generic";
        $parameters = [$this->getName()];

        if($this->deathCause === null && ($attacker = $source?->getAttackerName()) !== null){
            $parameters[] = $attacker;
        }

        $this->wake();
        $this->dismountVehicle();
        $this->deathCause = null;
        $this->dead = true;
        $this->effects->clear();

        $keep = $this->session->getServer()->isKeepInventory();
        $event = new PlayerDeathEvent($this, $source, new Translation($cause, $parameters), $keep ? [] : $this->getInventoryDrops(), $keep);
        $event->call();

        if(!$event->getKeepInventory()){
            $this->moveUIItemsToInventory();

            foreach([$this->inventory, $this->armorInventory, $this->offHandInventory] as $inventory){
                $inventory->clearAll();
            }

            ExperienceOrb::award($this->world, $this->position, min($this->xpLevel * 7, 100));
            $this->xpLevel = 0;
            $this->xpProgress = 0.0;
        }

        foreach($event->getDrops() as $item){
            $this->world->dropItem($this->position, $item);
        }

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendActorEvent($this, ActorEventPacket::DEATH);
        }

        $this->session->onDeath($cause, $parameters);
        $this->session->getServer()->onPlayerDeath($event, $cause, $parameters);
    }

    /**
     * Everything the player carries that drops when they die, curse of vanishing left out.
     *
     * @return list<Item>
     */
    private function getInventoryDrops() : array{
        $drops = [];

        foreach([$this->inventory, $this->armorInventory, $this->offHandInventory, $this->uiInventory] as $inventory){
            foreach($inventory->getContents() as $item){
                if(!$item->hasEnchantment(Enchantment::VANISHING)){
                    $drops[] = $item;
                }
            }
        }

        return $drops;
    }

    public function tick(int $currentTick) : void{
        if(!$this->spawned || $this->dead){
            return;
        }

        $this->effects->tick();
        $this->tickInvulnerability();
        $this->tickFire();

        if($this->dead){
            return;
        }

        if(!$this->gameMode->isInvulnerable()){
            $this->tickHunger($this->session->getServer()->getDifficulty());
        }

        $this->recipeBook->tick($currentTick);

        if($this->sleepingAt !== null){
            ++$this->sleepTicks;
        }

        if($this->position->y < $this->world->getMinY() - self::VOID_DEPTH && $currentTick - $this->lastVoidDamageTick >= self::VOID_DAMAGE_TICKS){
            $this->lastVoidDamageTick = $currentTick;
            $this->damage(new DamageSource(DamageSource::CAUSE_VOID, self::VOID_DAMAGE));
        }

        if($currentTick - $this->lastSuffocationTick >= self::SUFFOCATION_TICKS && $this->isHeadInBlock()){
            $this->lastSuffocationTick = $currentTick;
            $this->damage(new DamageSource(DamageSource::CAUSE_SUFFOCATION, self::SUFFOCATION_DAMAGE));
        }

        $this->tickBlockContact();
        $this->tickTurtleHelmet();
        $this->tickAir();
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

    private function tickBlockContact() : void{
        $moved = $this->movedSinceContact;
        $this->movedSinceContact = false;

        if(!$this->gameMode->hasCollision() || $this->gameMode->isInvulnerable()){
            return;
        }

        $half = $this->getWidth() / 2 - 0.001;
        [$minX, $minY, $minZ] = [$this->position->x - $half, $this->position->y + 0.001, $this->position->z - $half];
        [$maxX, $maxY, $maxZ] = [$this->position->x + $half, $this->position->y + $this->getHeight() - 0.001, $this->position->z + $half];
        $hurt = null;
        $burn = 0;
        $wet = false;

        for($x = (int) floor($minX); $x <= (int) floor($maxX); ++$x){
            for($z = (int) floor($minZ); $z <= (int) floor($maxZ); ++$z){
                $chunk = $this->world->getChunk($x >> 4, $z >> 4);

                if($chunk === null){
                    continue;
                }

                for($y = max(Chunk::MIN_Y, (int) floor($minY - 0.01)); $y <= min(Chunk::MAX_Y, (int) floor($maxY)); ++$y){
                    $block = $chunk->getBlock($x & 15, $y, $z & 15);
                    $inside = $y + 1 > $minY;

                    [$cause, $amount, $seconds] = match(true){
                        $block->getName() === BlockIds::CACTUS => [DamageSource::CAUSE_CONTACT, 1.0, 0],
                        !$inside => [null, 0.0, 0],
                        $block->getName() === BlockIds::FIRE => [DamageSource::CAUSE_FIRE, 1.0, self::FIRE_SECONDS],
                        $block->getName() === BlockIds::SOUL_FIRE => [DamageSource::CAUSE_FIRE, 2.0, self::FIRE_SECONDS],
                        $block->getName() === BlockIds::LAVA, $block->getName() === BlockIds::FLOWING_LAVA => [DamageSource::CAUSE_LAVA, 4.0, self::LAVA_SECONDS],
                        $block->getName() === BlockIds::CAMPFIRE && $block->getProperty(BlockProperties::EXTINGUISHED) === false => [DamageSource::CAUSE_FIRE, 1.0, 0],
                        $block->getName() === BlockIds::SOUL_CAMPFIRE && $block->getProperty(BlockProperties::EXTINGUISHED) === false => [DamageSource::CAUSE_FIRE, 2.0, 0],
                        $block->getName() === BlockIds::CAULDRON && $block->getProperty(BlockProperties::FILL_LEVEL) > 0 => $block->getProperty(BlockProperties::CAULDRON_LIQUID) === "lava" ? [DamageSource::CAUSE_LAVA, 4.0, self::LAVA_SECONDS] : [null, -1.0, 0],
                        $block->getName() === BlockIds::POWDER_SNOW => [null, -1.0, 0],
                        $block->getName() === BlockIds::SWEET_BERRY_BUSH && $moved && $block->getProperty(BlockProperties::GROWTH) !== 0 => [DamageSource::CAUSE_SWEET_BERRY, 1.0, 0],
                        default => [null, 0.0, 0]
                    };

                    if($amount < 0){
                        $wet = true;
                    }elseif($cause !== null && ($hurt === null || $amount > $hurt->getAmount())){
                        $hurt = new DamageSource($cause, $amount);
                    }

                    $burn = max($burn, $seconds);
                }
            }
        }

        $below = $this->position->add(0, -0.2, 0);

        if($hurt === null && $this->onGround && !$this->sneaking && $this->armorInventory->getBoots()->getEnchantmentLevel(Enchantment::FROST_WALKER) === 0 && $below->getFloorY() >= Chunk::MIN_Y && $this->world->isChunkLoaded($below->getFloorX() >> 4, $below->getFloorZ() >> 4) && $this->world->getBlock($below->getFloorX(), $below->getFloorY(), $below->getFloorZ())->getName() === BlockIds::MAGMA){
            $hurt = new DamageSource(DamageSource::CAUSE_MAGMA, 1.0);
        }

        if($wet){
            $this->extinguish();
        }elseif($burn > 0){
            $this->setOnFire($burn);
        }

        if($hurt !== null){
            $this->damage($hurt);
        }
    }

    private function tickTurtleHelmet() : void{
        if($this->armorInventory->getHelmet()->getId() !== ItemIds::TURTLE_HELMET || $this->isHeadInWater()){
            return;
        }

        if(($this->effects->get(Effect::WATER_BREATHING)?->getRemaining() ?? 0) <= self::TURTLE_BREATHING_TICKS - 20){
            $this->effects->add(new EffectInstance(Effect::WATER_BREATHING, self::TURTLE_BREATHING_TICKS, 0, false, false));
        }
    }

    public function canBreatheUnderwater() : bool{
        if($this->gameMode->isInvulnerable() || parent::canBreatheUnderwater()){
            return true;
        }

        $eyes = $this->getEyePosition();
        $y = $eyes->getFloorY();

        return $y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && $this->world->getChunk($eyes->getFloorX() >> 4, $eyes->getFloorZ() >> 4)?->getBlock($eyes->getFloorX() & 15, $y, $eyes->getFloorZ() & 15)->getName() === BlockIds::BUBBLE_COLUMN;
    }

    protected function getRespirationLevel() : int{
        return $this->armorInventory->getHelmet()->getEnchantmentLevel(Enchantment::RESPIRATION);
    }

    protected function onAirChanged() : void{
        if($this->spawned){
            $this->session->sendOwnMetadata();
        }
    }

    public function isUsingItem() : bool{
        return $this->itemUser->isUsing();
    }

    /**
     * @internal
     */
    public function onUsingItemChanged() : void{
        if($this->spawned){
            $this->broadcastEffectVisuals();
        }
    }

    /**
     * @internal
     *
     * @param bool $onGround the client stopped against something below it
     * @param bool $jumped   the client started a jump this tick
     */
    public function onClientMove(Vector3 $from, bool $onGround, bool $jumped) : void{
        $dx = $this->position->x - $from->x;
        $dy = $this->position->y - $from->y;
        $dz = $this->position->z - $from->z;
        $horizontal = sqrt($dx * $dx + $dz * $dz);

        if($this->swimming || $this->isHeadInWater()){
            $this->exhaust(0.01 * sqrt($horizontal * $horizontal + $dy * $dy));
        }elseif($this->isInWater()){
            $this->exhaust(0.01 * $horizontal);
        }elseif($this->sprinting && $onGround){
            $this->exhaust(0.1 * $horizontal);
        }

        if($jumped){
            $this->exhaust($this->sprinting ? 0.2 : 0.05);
            (new PlayerJumpEvent($this))->call();
        }

        if(abs($dx) >= 0.003 || abs($dz) >= 0.003){
            $this->movedSinceContact = true;
        }

        $this->onGround = $onGround;
        $this->updateFallDistance($this->position->y - $from->y, $onGround);

        if($onGround && $this->gameMode->hasCollision()){
            BigDripleaf::onStandingAt($this->world, $this->position);
        }
    }

    public function isOnGround() : bool{
        return $this->onGround;
    }

    private function breakPainting(Painting $painting) : bool{
        $reach = ($this->gameMode === GameMode::CREATIVE ? self::CREATIVE_ATTACK_REACH : self::ATTACK_REACH) + self::REACH_LEEWAY;

        if($this->dead || $this->gameMode === GameMode::SPECTATOR || $painting->isClosed() || $painting->getWorld() !== $this->world || $painting->distanceFrom($this->getEyePosition()) > $reach){
            return false;
        }

        $this->swingArm(AnimatePacket::SWING_ATTACK);
        $painting->destroy(!$this->gameMode->hasInstantBuild());

        return true;
    }

    private function hitVehicle(Vehicle $vehicle) : bool{
        $reach = ($this->gameMode === GameMode::CREATIVE ? self::CREATIVE_ATTACK_REACH : self::ATTACK_REACH) + self::REACH_LEEWAY;

        if($this->dead || !$this->gameMode->allowsInteraction() || $vehicle->isClosed() || $vehicle->getWorld() !== $this->world || $vehicle->distanceToBox($this->getEyePosition()) > $reach){
            return false;
        }

        $this->swingArm(AnimatePacket::SWING_ATTACK);
        $vehicle->hurt(min(4.0, ToolInfo::getAttackDamage($this->inventory->getItemInHand()->getId())), $this);
        return true;
    }

    public function attackEntity(Entity $target) : bool{
        if($target instanceof Painting){
            return $this->breakPainting($target);
        }

        if($target instanceof Vehicle){
            return $this->hitVehicle($target);
        }

        if($this->dead || !$this->gameMode->allowsInteraction() || $target === $this || !$target instanceof Living || !$target->isAlive() || $target->isClosed() || $target->getWorld() !== $this->world){
            return false;
        }

        $reach = ($this->gameMode === GameMode::CREATIVE ? self::CREATIVE_ATTACK_REACH : self::ATTACK_REACH) + self::REACH_LEEWAY;

        if($target->distanceToBox($this->getEyePosition()) > $reach){
            return false;
        }

        if($target instanceof self && (!$this->session->getServer()->isPvpEnabled() || !$target->isSpawned())){
            return false;
        }

        $this->swingArm(AnimatePacket::SWING_ATTACK);
        $this->exhaust(0.1);

        $held = $this->inventory->getItemInHand();
        $maceSmashDamage = $held->getId() === ItemIds::MACE
            ? ToolInfo::getMaceSmashDamage($this->fallDistance, $held->getEnchantmentLevel(Enchantment::DENSITY))
            : null;
        $damage = $maceSmashDamage ?? ToolInfo::getAttackDamage($held->getId());
        $damage += 3.0 * $this->effects->getLevel(Effect::STRENGTH);
        $damage = max(0.0, $damage - 4.0 * $this->effects->getLevel(Effect::WEAKNESS));
        $critical = $maceSmashDamage === null && $this->isCriticalHit();

        if($critical){
            $damage *= self::CRITICAL_MULTIPLIER;
        }

        $bonus = self::getEnchantmentDamage($held, $target);
        $damage += $bonus;
        $position = $target->getPosition();

        $breachLevel = $held->getEnchantmentLevel(Enchantment::BREACH);
        $armorEffectiveness = max(0.0, 1.0 - 0.15 * $breachLevel);

        if(!$target->damage(new DamageSource(DamageSource::CAUSE_ENTITY_ATTACK, $damage, $this, $armorEffectiveness))){
            $this->broadcastNear($target, LevelSoundEventPacket::create(LevelSoundEvent::ATTACK_NODAMAGE, $position->x, $position->y, $position->z));
            return false;
        }

        if($maceSmashDamage !== null){
            $fallDistance = $this->fallDistance;
            $this->fallDistance = 0.0;
            $this->playMaceSmash($target, $damage);

            $windBurstLevel = $held->getEnchantmentLevel(Enchantment::WIND_BURST);

            if($windBurstLevel > 0){
                $this->applyWindBurst($windBurstLevel, $fallDistance);
            }
        }

        if($target->wasLastHitFresh()){
            $to = $target->getPosition();
            $extra = self::SPRINT_KNOCKBACK * ($held->getEnchantmentLevel(Enchantment::KNOCKBACK) + ($this->sprinting ? 1 : 0));
            $target->knockBack($to->x - $this->position->x, $to->z - $this->position->z, extra: $extra, yaw: $this->yaw);
        }

        $fireAspect = $held->getEnchantmentLevel(Enchantment::FIRE_ASPECT);

        if($fireAspect > 0 && $target->isAlive()){
            $target->setOnFire(4 * $fireAspect);
        }

        $bane = $held->getEnchantmentLevel(Enchantment::BANE_OF_ARTHROPODS);

        if($bane > 0 && $target->isArthropod() && $target->isAlive()){
            $target->getEffects()->add(new EffectInstance(Effect::SLOWNESS, 20 + mt_rand(0, 10 * $bane), 3));
        }

        $this->broadcastNear($target, LevelSoundEventPacket::create(LevelSoundEvent::ATTACK_STRONG, $position->x, $position->y, $position->z));

        if($critical){
            $this->broadcastNear($target, AnimatePacket::create(AnimatePacket::ACTION_CRITICAL_HIT, $target->getId()));
        }

        if($bonus > 0){
            $this->broadcastNear($target, AnimatePacket::create(AnimatePacket::ACTION_MAGIC_CRITICAL_HIT, $target->getId()));
        }

        if(!$this->gameMode->hasInstantBuild()){
            $this->wearHeld(ToolInfo::getAttackWear($held->getId()));
        }

        return true;
    }

    private function playMaceSmash(Living $target, float $damage) : void{
        if($damage < 7.0){
            return;
        }

        $position = $target->getPosition();
        $world = $target->getWorld();
        $world->broadcastPacket(
            $position->getFloorX(),
            $position->getFloorZ(),
            LevelEventPacket::create(LevelEvent::PARTICLE_SMASH_ATTACK_GROUND_DUST, $position->x, $position->y, $position->z)
        );
        $world->broadcastPacket(
            $position->getFloorX(),
            $position->getFloorZ(),
            LevelSoundEventPacket::create(
                $damage >= 16.0 ? LevelSoundEvent::MACE_HEAVY_SMASH_GROUND : LevelSoundEvent::MACE_SMASH_GROUND,
                $position->x,
                $position->y,
                $position->z
            )
        );
    }

    private function applyWindBurst(int $level, float $fallDistance) : void{
        $clampedHeight = min($fallDistance, 7.5);
        $verticalBoost = 0.72 + $clampedHeight * 0.10 + match($level){
            2 => 0.55,
            3 => 1.3,
            default => 0.0
        };
        $yaw = deg2rad($this->yaw);
        $forwardBoost = 0.08 + 0.02 * $level;
        $this->setMotion(new Vector3(-sin($yaw) * $forwardBoost, $verticalBoost, cos($yaw) * $forwardBoost));

        foreach($this->world->getEntities() as $entity){
            if(!($entity instanceof Living) || $entity === $this || $entity->isClosed()){
                continue;
            }

            $position = $entity->getPosition();
            $dx = $position->x - $this->position->x;
            $dy = $position->y - $this->position->y;
            $dz = $position->z - $this->position->z;
            $distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

            if($distance <= 0.0 || $distance > 2.5){
                continue;
            }

            $push = 0.6 + 0.15 * $level;
            $motion = $entity->getMotion();
            $entity->setMotion(new Vector3(
                $motion->x + $dx / $distance * $push,
                max($motion->y, 0.4 + 0.1 * $level),
                $motion->z + $dz / $distance * $push
            ));

            $entityPosition = $position->add(0, $entity->getEyeHeight() * 0.6, 0);
            $this->world->addParticle($entityPosition->x, $entityPosition->y, $entityPosition->z, ParticleEffect::WIND_EXPLOSION_EMITTER);
        }

        $position = $this->position->add(0, $this->getEyeHeight() * 0.6, 0);
        $this->world->addParticle($position->x, $position->y, $position->z, ParticleEffect::WIND_EXPLOSION_EMITTER);
        $this->world->broadcastPacket(
            $this->position->getFloorX(),
            $this->position->getFloorZ(),
            LevelSoundEventPacket::create(LevelSoundEvent::MACE_SMASH_AIR, $this->position->x, $this->position->y, $this->position->z)
        );
    }

    /**
     * What the weapon's enchantments add against $target: sharpness against anything, smite
     * against the undead, bane of arthropods against spiders and their kind.
     */
    public static function getEnchantmentDamage(Item $weapon, Living $target) : float{
        $bonus = self::SHARPNESS_DAMAGE * $weapon->getEnchantmentLevel(Enchantment::SHARPNESS);

        if($target->isUndead()){
            $bonus += self::SMITE_DAMAGE * $weapon->getEnchantmentLevel(Enchantment::SMITE);
        }

        if($target->isArthropod()){
            $bonus += self::SMITE_DAMAGE * $weapon->getEnchantmentLevel(Enchantment::BANE_OF_ARTHROPODS);
        }

        return $bonus;
    }

    protected function getArmorValues() : array{
        $defense = 0;
        $toughness = 0.0;

        foreach($this->armorInventory->getContents() as $item){
            $defense += ArmorInfo::getDefense($item->getId());
            $toughness += ArmorInfo::getToughness($item->getId());
        }

        return [$defense, $toughness];
    }

    protected function getProtectionPoints(DamageSource $source) : int{
        $points = 0;

        foreach($this->armorInventory->getContents() as $item){
            if($source->getCause() !== DamageSource::CAUSE_STARVATION){
                $points += $item->getEnchantmentLevel(Enchantment::PROTECTION);
            }

            $points += match(true){
                $source->isFire() => 2 * $item->getEnchantmentLevel(Enchantment::FIRE_PROTECTION),
                $source->getCause() === DamageSource::CAUSE_BLOCK_EXPLOSION, $source->getCause() === DamageSource::CAUSE_ENTITY_EXPLOSION => 2 * $item->getEnchantmentLevel(Enchantment::BLAST_PROTECTION),
                $source->getCause() === DamageSource::CAUSE_PROJECTILE => 2 * $item->getEnchantmentLevel(Enchantment::PROJECTILE_PROTECTION),
                $source->getCause() === DamageSource::CAUSE_FALL => 3 * $item->getEnchantmentLevel(Enchantment::FEATHER_FALLING),
                default => 0
            };
        }

        return $points;
    }

    public function getKnockbackResistance() : float{
        $resistance = 0.0;

        foreach($this->armorInventory->getContents() as $item){
            $resistance += ArmorInfo::getKnockbackResistance($item->getId());
        }

        return $resistance;
    }

    /**
     * Armor that stopped some of the hit wears by a quarter of it, at least 1; thorns may hit
     * the attacker back.
     */
    protected function onHurt(DamageSource $source) : void{
        if(!$source->isReducedByArmor() || $this->gameMode->hasInstantBuild()){
            return;
        }

        $attacker = $source->getAttacker();
        $hitsBack = $attacker instanceof Living && $attacker !== $this && $source->getCause() !== DamageSource::CAUSE_THORNS && ($source->getCause() === DamageSource::CAUSE_ENTITY_ATTACK || $source->getCause() === DamageSource::CAUSE_PROJECTILE);
        $wear = max(1, (int) floor($source->getAmount() / 4));

        foreach($this->armorInventory->getContents() as $slot => $item){
            if(ArmorInfo::getDefense($item->getId()) <= 0){
                continue;
            }

            $thorns = $hitsBack ? $item->getEnchantmentLevel(Enchantment::THORNS) : 0;
            $pieceWear = $wear;

            if($thorns > 0 && mt_rand(0, 99) < 15 * $thorns){
                $attacker->damage(new DamageSource(DamageSource::CAUSE_THORNS, $thorns > 10 ? $thorns - 10 : mt_rand(1, 4), $this));
                $pieceWear += 2;
            }

            $this->wearArmor($slot, $item, $pieceWear);
        }
    }

    private function wearArmor(int $slot, Item $item, int $amount) : void{
        if(!$item->hasDurability()){
            return;
        }

        $unbreaking = $item->getEnchantmentLevel(Enchantment::UNBREAKING);
        $taken = 0;

        for($i = 0; $i < $amount; ++$i){
            if($unbreaking === 0 || mt_rand(1, 100) <= 60 + intdiv(40, $unbreaking + 1)){
                ++$taken;
            }
        }

        if($taken === 0){
            return;
        }

        if($item->getDamage() + $taken >= $item->getMaxDurability()){
            $this->armorInventory->setItem($slot, Item::air());
            $this->session->sendDataPacket(LevelSoundEventPacket::create(LevelSoundEvent::BREAK, $this->position->x, $this->position->y, $this->position->z));
            return;
        }

        $this->armorInventory->setItem($slot, $item->setDamage($item->getDamage() + $taken));
    }

    protected function onFireChanged() : void{
        if($this->spawned){
            $this->broadcastEffectVisuals();
        }
    }

    /**
     * Falling, not standing, swimming, flying or blind: the next hit is a critical one.
     */
    public function isCriticalHit() : bool{
        return !$this->onGround && $this->fallDistance > 0 && !$this->flying && !$this->isInWater() && !$this->effects->has(Effect::BLINDNESS);
    }

    /**
     * The player's own client moves the player, so a push has to go to it.
     */
    public function setMotion(Vector3 $motion) : void{
        parent::setMotion($motion);
        $this->getMovementCheck()->onPushed($motion);

        if($this->spawned){
            $this->session->sendDataPacket(SetActorMotionPacket::create($this->id, $motion->x, $motion->y, $motion->z, $this->session->getLastInputTick()));
        }
    }

    /**
     * Sends $packet to everyone who sees $entity, the player and $entity included.
     */
    private function broadcastNear(Entity $entity, Packet $packet) : void{
        $targets = $entity->getViewers();
        $targets[$this->id] = $this;

        if($entity instanceof self){
            $targets[$entity->getId()] = $entity;
        }

        foreach($targets as $player){
            $player->getNetworkSession()->sendDataPacket($packet);
        }
    }

    /**
     * Wears the held item down by $amount uses, unbreaking can save it.
     */
    public function wearHeld(int $amount) : void{
        $item = $this->inventory->getItemInHand();

        if(!$item->hasDurability()){
            return;
        }

        $unbreaking = $item->getEnchantmentLevel(Enchantment::UNBREAKING);

        if($unbreaking > 0 && mt_rand(0, $unbreaking) !== 0){
            return;
        }

        if($item->getDamage() + $amount >= $item->getMaxDurability()){
            $this->inventory->setItemInHand(Item::air());
            $this->session->sendDataPacket(LevelSoundEventPacket::create(LevelSoundEvent::BREAK, $this->position->x, $this->position->y, $this->position->z));
            return;
        }

        $this->inventory->setItemInHand($item->setDamage($item->getDamage() + $amount));
    }

    private function updateFallDistance(float $dy, bool $onGround) : void{
        if($this->flying || $this->swimming || $this->isInWater() || $this->effects->has(Effect::SLOW_FALLING) || $this->effects->has(Effect::LEVITATION)){
            $this->fallDistance = 0.0;
            return;
        }

        if($this->isCaughtByBlock()){
            $this->fallDistance = 0.0;
            return;
        }

        if($onGround){
            $this->fallDistance -= min($dy, 0.0);
            $fall = $this->fallDistance;
            $this->fallDistance = 0.0;

            $below = $this->position->add(0, -0.2, 0);
            $landedOn = $below->getFloorY() >= Chunk::MIN_Y && $this->world->isChunkLoaded($below->getFloorX() >> 4, $below->getFloorZ() >> 4) ? $this->world->getBlock($below->getFloorX(), $below->getFloorY(), $below->getFloorZ())->getName() : BlockIds::AIR;
            $bounces = $landedOn === BlockIds::SLIME && !$this->sneaking;

            if($landedOn === BlockIds::FARMLAND){
                Farmland::trample($this->world, $below->getFloorX(), $below->getFloorY(), $below->getFloorZ(), $this, $fall);
            }

            if($bounces){
                $this->movementCheck?->onBounce($fall);
            }elseif($landedOn === BlockIds::BED){
                $this->movementCheck?->onBounce($fall * 0.5);
            }

            $tip = $landedOn === BlockIds::POINTED_DRIPSTONE ? $this->world->getBlock($below->getFloorX(), $below->getFloorY(), $below->getFloorZ()) : null;
            $stalagmite = $tip !== null && !PointedDripstone::isHanging($tip) && $tip->getProperty(BlockProperties::DRIPSTONE_THICKNESS) === PointedDripstone::TIP;

            $multiplier = match(true){
                $bounces => 0.0,
                $stalagmite => 2.0,
                $landedOn === BlockIds::HONEY_BLOCK, $landedOn === BlockIds::HAY_BLOCK => 0.2,
                $landedOn === BlockIds::BED => 0.5,
                default => 1.0
            };

            $damage = ($fall + ($stalagmite ? 2.5 : 0.0) - self::SAFE_FALL_DISTANCE - $this->effects->getLevel(Effect::JUMP_BOOST)) * $multiplier;

            if($damage >= 0.5){
                $this->damage(new DamageSource($stalagmite ? DamageSource::CAUSE_STALAGMITE : DamageSource::CAUSE_FALL, ceil($damage)));
            }
        }elseif($dy < 0){
            $this->fallDistance -= $dy;
        }else{
            $this->fallDistance = 0.0;
        }
    }

    /**
     * Whether the player climbs something or is stuck in a cobweb, a sweet berry bush or powder snow.
     */
    public function isCaughtByBlock() : bool{
        $x = $this->position->getFloorX();
        $z = $this->position->getFloorZ();

        if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return false;
        }

        foreach([$this->position->getFloorY(), (int) floor($this->position->y + 1.0)] as $y){
            if($y >= Chunk::MIN_Y && $y <= Chunk::MAX_Y && isset(self::BREAKS_FALL[$this->world->getBlock($x, $y, $z)->getName()])){
                return true;
            }
        }

        return false;
    }

    /**
     * Water at the feet or the head breaks a fall.
     */
    public function isInWater() : bool{
        return $this->isWaterAt($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ()) || $this->isHeadInWater();
    }

    public function isHeadInWater() : bool{
        $eyes = $this->getEyePosition();

        return $this->isWaterAt($eyes->getFloorX(), $eyes->getFloorY(), $eyes->getFloorZ());
    }

    /**
     * Water, or a block standing in water like kelp or a waterlogged slab.
     */
    private function isWaterAt(int $x, int $y, int $z) : bool{
        $chunk = $this->world->getChunk($x >> 4, $z >> 4);

        if($chunk === null || $y < Chunk::MIN_Y || $y > Chunk::MAX_Y){
            return false;
        }

        foreach([0, 1] as $layer){
            $name = $chunk->getBlock($x & 15, $y, $z & 15, $layer)->getName();

            if($name === BlockIds::WATER || $name === BlockIds::FLOWING_WATER || $name === BlockIds::BUBBLE_COLUMN || $name === BlockIds::KELP || $name === BlockIds::SEAGRASS){
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
        if($sprinting !== $this->sprinting){
            $this->sprinting = $sprinting;
            (new PlayerToggleSprintEvent($this, $sprinting))->call();
        }
    }

    public function isSneaking() : bool{
        return $this->sneaking;
    }

    /**
     * @internal
     */
    public function setSneaking(bool $sneaking) : void{
        if($sneaking !== $this->sneaking){
            $this->sneaking = $sneaking;
            (new PlayerToggleSneakEvent($this, $sneaking))->call();
        }
    }

    public function getInteractText() : string{
        return $this->interactText;
    }

    /**
     * @internal
     */
    public function setInteractText(string $text) : void{
        if($this->interactText !== $text){
            $this->interactText = $text;
            $this->session->sendOwnMetadata();
        }
    }

    public function isSwimming() : bool{
        return $this->swimming;
    }

    /**
     * @internal
     */
    public function setSwimming(bool $swimming) : void{
        if($swimming !== $this->swimming){
            $this->swimming = $swimming;
            (new PlayerToggleSwimEvent($this, $swimming))->call();
        }
    }

    public function getFallDistance() : float{
        return $this->fallDistance;
    }

    public function respawn() : void{
        if(!$this->dead){
            return;
        }

        $this->dead = false;
        $this->fireTicks = 0;
        $this->resetVitals();
        $this->flying = $this->gameMode->forcesFlying();
        $this->swimming = false;
        $this->sprinting = false;
        $this->lastDamageSource = null;

        [$world, $position] = $this->getSpawnLocation();

        if($this->spawnAtBed && $this->spawnPosition !== null){
            $standUp = Beds::findStandUpSpot($world, $this->spawnPosition->getFloorX(), $this->spawnPosition->getFloorY(), $this->spawnPosition->getFloorZ());

            if($standUp === null){
                $this->setSpawnPosition(null);
                $this->sendTranslation("%tile.bed.notValid");
                [$world, $position] = $this->getSpawnLocation();
            }else{
                $position = $standUp;
            }
        }

        $event = new PlayerRespawnEvent($this, $world, $position);
        $event->call();

        $this->despawnFromAll();
        $this->doTeleport($event->getPosition(), null, null, $event->getWorld());

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
        $this->air = self::MAX_AIR;
        $this->breathing = true;
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
     * Disconnects the player and shows $reason on their screen. False when a plugin stopped it.
     */
    public function kick(string $reason) : bool{
        $event = new PlayerKickEvent($this, $reason);
        $event->call();

        if($event->isCancelled()){
            return false;
        }

        $this->session->disconnect($event->getReason());
        return true;
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

    public function sendCommandFeedback(CommandFeedback $feedback) : void{
        $this->session->sendCommandFeedback($feedback);
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

        $this->wake();
        $this->vehicle?->removePassenger($this, null, true);

        foreach($this->world->getEntities() as $entity){
            $entity->despawnFrom($this, false);
        }

        parent::close();
    }
}