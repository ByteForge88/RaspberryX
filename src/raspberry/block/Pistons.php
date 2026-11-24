<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\block\entity\BlockEntityFactory;
use raspberry\block\entity\Chest;
use raspberry\block\entity\MovingBlock;
use raspberry\block\entity\PistonArm;
use raspberry\block\redstone\RedstoneSignal;
use raspberry\block\redstone\RedstoneUpdater;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\Player;

use raspberry\world\World;

use function abs;
use function array_reverse;
use function preg_match;

class Pistons{

    public const int PUSH_LIMIT = 12;

    private const string IMMOVABLE = '/^minecraft:(obsidian|crying_obsidian|reinforced_deepslate|respawn_anchor|beacon|enchanting_table|ender_chest|grindstone|jukebox|lodestone|' .
    'mob_spawner|trial_spawner|vault|end_gateway|end_portal|end_portal_frame|portal|(calibrated_)?sculk_sensor|sculk_catalyst|sculk_shrieker|creaking_heart|light_block_\d+|' .
    'structure_block|structure_void|jigsaw|barrier|bedrock|border_block|allow|deny|(chain_|repeating_)?command_block|moving_block|(sticky_)?piston_arm_collision)$/D';

    private const string BREAKS = '/^minecraft:(\w+_skull|(zombie|player|creeper|dragon|piglin)_head|flower_pot|dragon_egg|chorus_plant|chorus_flower|pointed_dripstone|big_dripleaf|decorated_pot)$/D';

    private const string LIQUIDS = '/^minecraft:(flowing_)?(water|lava)$|^minecraft:bubble_column$/D';

    private const string PUSH_ONLY = '/glazed_terracotta$/D';

    private const float REACH = 16.0;

    public static function isPiston(string $name) : bool{
        return $name === BlockIds::PISTON || $name === BlockIds::STICKY_PISTON;
    }

    public static function isHead(string $name) : bool{
        return $name === BlockIds::PISTON_ARM_COLLISION || $name === BlockIds::STICKY_PISTON_ARM_COLLISION;
    }

    public static function isSticky(BlockState $block) : bool{
        return $block->getName() === BlockIds::SLIME || $block->getName() === BlockIds::HONEY_BLOCK;
    }

    public static function canStick(BlockState $first, BlockState $second) : bool{
        $a = $first->getName();
        $b = $second->getName();

        if(($a === BlockIds::SLIME && $b === BlockIds::HONEY_BLOCK) || ($a === BlockIds::HONEY_BLOCK && $b === BlockIds::SLIME)){
            return false;
        }

        return self::isSticky($first) || self::isSticky($second);
    }

    //bedrock stores the horizontal sides of a piston turned around
    public static function getFacing(BlockState $block) : int{
        $value = (int) $block->getProperty(BlockProperties::FACING_DIRECTION);

        return Facing::isHorizontal($value) ? Facing::opposite($value) : $value;
    }

    public static function breaksWhenPushed(BlockState $block) : bool{
        $name = $block->getName();

        if(Rails::isRail($name)){
            return false;
        }

        return !BlockCollision::isSolid($block) || BlockSupport::needsSupport($block) || preg_match(self::BREAKS, $name) === 1;
    }

    public static function canMove(World $world, int $x, int $y, int $z, BlockState $block, bool $allowDestroy, bool $forward) : bool{
        $name = $block->getName();

        if(self::isPiston($name)){
            $arm = $world->getBlockEntity($x, $y, $z);

            return !$arm instanceof PistonArm || $arm->getState() === PistonArm::RETRACTED;
        }

        if(preg_match(self::IMMOVABLE, $name) === 1){
            return false;
        }

        if(preg_match(self::LIQUIDS, $name) === 1 || self::breaksWhenPushed($block)){
            return $allowDestroy;
        }

        if(!BlockBreakInfo::get($name)->isBreakable()){
            return false;
        }

        return preg_match(self::PUSH_ONLY, $name) !== 1 || $forward;
    }

    public static function isPowered(RedstoneSignal $signal, int $x, int $y, int $z, int $facing) : bool{
        foreach(Facing::ALL as $face){
            if($face !== $facing && $signal->getPowerFrom($x, $y, $z, $face) > 0){
                return true;
            }
        }

        return false;
    }

    public static function wantsToMove(World $world, RedstoneSignal $signal, int $x, int $y, int $z, BlockState $piston) : bool{
        $arm = $world->getBlockEntity($x, $y, $z);

        if($arm instanceof PistonArm && $arm->isMoving()){
            return false;
        }

        $extended = $arm instanceof PistonArm ? $arm->getState() === PistonArm::EXTENDED : self::hasHead($world, $x, $y, $z, $piston);

        return self::isPowered($signal, $x, $y, $z, self::getFacing($piston)) !== $extended;
    }

    public static function act(World $world, RedstoneSignal $signal, int $x, int $y, int $z, BlockState $piston) : void{
        $arm = self::getArm($world, $x, $y, $z, $piston);

        if($arm->isMoving()){
            return;
        }

        $facing = self::getFacing($piston);
        $powered = self::isPowered($signal, $x, $y, $z, $facing);

        if($powered && $arm->getState() === PistonArm::RETRACTED){
            self::extend($world, $x, $y, $z, $piston, $facing, $arm);
        }elseif(!$powered && $arm->getState() === PistonArm::EXTENDED){
            self::retract($world, $x, $y, $z, $piston, $facing, $arm);
        }
    }

    public static function isMovedBy(World $world, MovingBlock $moving) : bool{
        [$x, $y, $z] = [$moving->getPistonX(), $moving->getPistonY(), $moving->getPistonZ()];

        if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
            return false;
        }

        $arm = $world->getBlockEntity($x, $y, $z);

        return $arm instanceof PistonArm && $arm->isMoving();
    }

    public static function finish(World $world, PistonArm $arm) : void{
        [$x, $y, $z] = [$arm->getX(), $arm->getY(), $arm->getZ()];
        $piston = $world->getBlock($x, $y, $z);
        $extending = $arm->isExtending();
        $attached = $arm->finish();

        if(self::isPiston($piston->getName())){
            $facing = self::getFacing($piston);
            [$dx, $dy, $dz] = Facing::offset($extending ? $facing : Facing::opposite($facing));

            foreach($attached as [$bx, $by, $bz]){
                $moving = $world->getBlockEntity($bx + $dx, $by + $dy, $bz + $dz);

                if($moving instanceof MovingBlock){
                    self::settle($world, $moving);
                }
            }
        }

        $world->sendBlockEntity($arm);
        $world->getRedstoneUpdater()->update($x, $y, $z);
    }

    public static function settle(World $world, MovingBlock $moving) : void{
        [$x, $y, $z] = [$moving->getX(), $moving->getY(), $moving->getZ()];

        if($world->getBlockEntity($x, $y, $z) !== $moving || $world->getBlock($x, $y, $z)->getName() !== BlockIds::MOVING_BLOCK){
            return;
        }

        $world->setBlock($x, $y, $z, $moving->getBlock());
        $nbt = $moving->getEntityData();

        if($nbt === null || $world->getBlock($x, $y, $z)->getName() !== $moving->getBlock()->getName()){
            return;
        }

        $entity = BlockEntityFactory::load($nbt->setInt("x", $x)->setInt("y", $y)->setInt("z", $z));

        if($entity !== null){
            $world->addBlockEntity($entity);
        }
    }

    public static function pushEntities(World $world, PistonArm $arm) : void{
        [$x, $y, $z] = [$arm->getX(), $arm->getY(), $arm->getZ()];
        $piston = $world->getBlock($x, $y, $z);

        if(!self::isPiston($piston->getName())){
            return;
        }

        $facing = self::getFacing($piston);
        $extending = $arm->isExtending();
        $direction = $extending ? $facing : Facing::opposite($facing);
        [$dx, $dy, $dz] = Facing::offset($direction);
        $moved = $extending ? $arm->getProgress() : 1.0 - $arm->getProgress();
        $boxes = [];

        foreach($arm->getAttached() as [$bx, $by, $bz]){
            $boxes[] = [$bx + $dx * $moved, $by + $dy * $moved, $bz + $dz * $moved];
        }

        if($extending){
            $boxes[] = [$x + $dx * $moved, $y + $dy * $moved, $z + $dz * $moved];
        }

        $step = new Vector3($dx * PistonArm::STEP, $dy * PistonArm::STEP, $dz * PistonArm::STEP);

        foreach($world->getEntities() as $entity){
            if($entity instanceof Player || $entity->isClosed()){
                continue;
            }

            $position = $entity->getPosition();

            if(abs($position->x - $x) > self::REACH || abs($position->y - $y) > self::REACH || abs($position->z - $z) > self::REACH){
                continue;
            }

            $half = $entity->getWidth() / 2;

            foreach($boxes as [$minX, $minY, $minZ]){
                if($position->x + $half > $minX && $position->x - $half < $minX + 1 && $position->y + $entity->getHeight() > $minY && $position->y < $minY + 1 && $position->z + $half > $minZ && $position->z - $half < $minZ + 1){
                    $entity->teleport($position->add($step->x, $step->y, $step->z));
                    break;
                }
            }
        }
    }

    public static function onBlockChanged(World $world, int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        $name = $old->getName();

        if($name === $new->getName()){
            return;
        }

        if(self::isPiston($name)){
            [$dx, $dy, $dz] = Facing::offset(self::getFacing($old));
            $head = $world->getBlock($x + $dx, $y + $dy, $z + $dz);

            if(self::isHead($head->getName()) && $head->getProperty(BlockProperties::FACING_DIRECTION) === $old->getProperty(BlockProperties::FACING_DIRECTION)){
                $world->setBlock($x + $dx, $y + $dy, $z + $dz, RedstoneUpdater::air());
            }
        }elseif(self::isHead($name)){
            [$dx, $dy, $dz] = Facing::offset(Facing::opposite(self::getFacing($old)));
            [$bx, $by, $bz] = [$x + $dx, $y + $dy, $z + $dz];
            $base = $world->getBlock($bx, $by, $bz);
            $arm = $world->getBlockEntity($bx, $by, $bz);

            if(self::isPiston($base->getName()) && $base->getProperty(BlockProperties::FACING_DIRECTION) === $old->getProperty(BlockProperties::FACING_DIRECTION) && $arm instanceof PistonArm && $arm->getState() === PistonArm::EXTENDED){
                BlockSupport::popOff($world, $bx, $by, $bz, $base);
            }
        }
    }

    private static function getArm(World $world, int $x, int $y, int $z, BlockState $piston) : PistonArm{
        $arm = $world->getBlockEntity($x, $y, $z);

        if(!$arm instanceof PistonArm){
            $arm = new PistonArm($x, $y, $z);

            if(self::hasHead($world, $x, $y, $z, $piston)){
                $arm->setExtended();
            }

            $world->addBlockEntity($arm);
        }

        $arm->setSticky($piston->getName() === BlockIds::STICKY_PISTON);

        return $arm;
    }

    private static function hasHead(World $world, int $x, int $y, int $z, BlockState $piston) : bool{
        [$dx, $dy, $dz] = Facing::offset(self::getFacing($piston));

        return self::isHead($world->getBlock($x + $dx, $y + $dy, $z + $dz)->getName());
    }

    private static function extend(World $world, int $x, int $y, int $z, BlockState $piston, int $facing, PistonArm $arm) : void{
        $structure = new PistonStructure($world, $x, $y, $z, $facing, true);

        if(!$structure->resolve()){
            return;
        }

        $arm->start(true);
        self::move($world, $x, $y, $z, $facing, $structure, $arm, true);

        [$dx, $dy, $dz] = Facing::offset($facing);
        $head = $piston->getName() === BlockIds::STICKY_PISTON ? BlockIds::STICKY_PISTON_ARM_COLLISION : BlockIds::PISTON_ARM_COLLISION;
        $world->setBlock($x + $dx, $y + $dy, $z + $dz, Server::getInstance()->getBlockStateRegistry()->getDefaultState($head)->withProperty(BlockProperties::FACING_DIRECTION, $piston->getProperty(BlockProperties::FACING_DIRECTION)));

        $world->sendBlockEntity($arm);
        self::playSound($world, LevelSoundEvent::PISTON_OUT, $x, $y, $z);
    }

    private static function retract(World $world, int $x, int $y, int $z, BlockState $piston, int $facing, PistonArm $arm) : void{
        $arm->start(false);

        [$dx, $dy, $dz] = Facing::offset($facing);

        if(self::isHead($world->getBlock($x + $dx, $y + $dy, $z + $dz)->getName())){
            $world->setBlock($x + $dx, $y + $dy, $z + $dz, RedstoneUpdater::air());
        }

        if($arm->isSticky()){
            $structure = new PistonStructure($world, $x, $y, $z, $facing, false);

            if($structure->resolve()){
                self::move($world, $x, $y, $z, $facing, $structure, $arm, false);
            }
        }

        $world->sendBlockEntity($arm);
        self::playSound($world, LevelSoundEvent::PISTON_IN, $x, $y, $z);
    }

    private static function move(World $world, int $x, int $y, int $z, int $facing, PistonStructure $structure, PistonArm $arm, bool $extending) : void{
        [$dx, $dy, $dz] = Facing::offset($structure->getPushDirection());
        $moving = [];
        $attached = [];

        foreach($structure->getToPush() as [$bx, $by, $bz]){
            $moving[] = [$bx, $by, $bz, $world->getBlock($bx, $by, $bz), self::takeBlockEntity($world, $bx, $by, $bz)];
            $attached[] = [$bx, $by, $bz];
        }

        foreach(array_reverse($structure->getToDestroy()) as [$bx, $by, $bz]){
            $block = $world->getBlock($bx, $by, $bz);

            if(preg_match(self::LIQUIDS, $block->getName()) === 1){
                $world->setBlock($bx, $by, $bz, RedstoneUpdater::air());
            }else{
                BlockSupport::popOff($world, $bx, $by, $bz, $block);
            }
        }

        $arm->setAttached($attached);
        $filled = [];
        $movingBlock = Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::MOVING_BLOCK);

        foreach(array_reverse($moving) as [$bx, $by, $bz, $block, $nbt]){
            [$tx, $ty, $tz] = [$bx + $dx, $by + $dy, $bz + $dz];

            $world->setBlock($tx, $ty, $tz, $movingBlock);

            $entity = new MovingBlock($tx, $ty, $tz);
            $entity->setMoving($block, $nbt, $x, $y, $z, $extending);
            $world->addBlockEntity($entity);

            $filled["{$tx}:{$ty}:{$tz}"] = true;
        }

        [$fx, $fy, $fz] = Facing::offset($facing);

        foreach($moving as [$bx, $by, $bz]){
            $isHead = $extending && $bx === $x + $fx && $by === $y + $fy && $bz === $z + $fz;

            if(!$isHead && !isset($filled["{$bx}:{$by}:{$bz}"])){
                $world->setBlock($bx, $by, $bz, RedstoneUpdater::air());
            }
        }
    }

    private static function takeBlockEntity(World $world, int $x, int $y, int $z) : ?CompoundTag{
        $entity = $world->getBlockEntity($x, $y, $z);

        if($entity === null || $entity instanceof MovingBlock){
            return null;
        }

        foreach($entity->getViewers() as $player){
            $player->getNetworkSession()->getInventoryManager()?->closeWindow(true);
        }

        $pair = $entity instanceof Chest ? $entity->getPair() : null;

        if($entity instanceof Chest && $pair !== null){
            [$pairX, $pairZ] = $pair;
            $other = $world->isChunkLoaded($pairX >> 4, $pairZ >> 4) ? $world->getBlockEntity($pairX, $y, $pairZ) : null;
            $entity->unpair();

            if($other instanceof Chest){
                $other->unpair();
                $world->sendBlockEntity($other);
            }
        }

        return $entity->save();
    }

    private static function playSound(World $world, string $sound, int $x, int $y, int $z) : void{
        $world->broadcastPacket($x, $z, LevelSoundEventPacket::create($sound, $x + 0.5, $y + 0.5, $z + 0.5));
    }
}