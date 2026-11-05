<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use function cos;
use function deg2rad;
use function sin;
use function str_ends_with;

class Sign extends BlockEntity{

    public const ID_SIGN = "Sign";
    public const ID_HANGING_SIGN = "HangingSign";

    public const MAX_TEXT_LENGTH = 256;

    /** black, as ARGB */
    private const DEFAULT_COLOR = -16777216;

    private string $saveId;

    /** @var array{string, string} front, back */
    private array $text = ["", ""];

    /** @var array{string, string} XUID of who wrote each side */
    private array $owners = ["", ""];

    /** @var array{int, int} */
    private array $colors = [self::DEFAULT_COLOR, self::DEFAULT_COLOR];

    /** @var array{bool, bool} glow ink */
    private array $glowing = [false, false];

    private bool $waxed = false;

    /** runtime ID of the player editing it, not saved */
    private ?int $editor = null;

    public function __construct(int $x, int $y, int $z, string $saveId = self::ID_SIGN){
        parent::__construct($x, $y, $z);

        $this->saveId = $saveId;
    }

    public function getSaveId() : string{
        return $this->saveId;
    }

    public function getText(bool $front) : string{
        return $this->text[$front ? 0 : 1];
    }

    public function setText(bool $front, string $text, string $ownerXuid) : void{
        $this->text[$front ? 0 : 1] = $text;
        $this->owners[$front ? 0 : 1] = $ownerXuid;
        $this->setChanged();
    }

    /**
     * The text color as ARGB.
     */
    public function getColor(bool $front) : int{
        return $this->colors[$front ? 0 : 1];
    }

    public function setColor(bool $front, int $argb) : void{
        $this->colors[$front ? 0 : 1] = $argb;
        $this->setChanged();
    }

    public function isGlowing(bool $front) : bool{
        return $this->glowing[$front ? 0 : 1];
    }

    public function setGlowing(bool $front, bool $glowing) : void{
        $this->glowing[$front ? 0 : 1] = $glowing;
        $this->setChanged();
    }

    public function isWaxed() : bool{
        return $this->waxed;
    }

    public function setWaxed(bool $waxed) : void{
        $this->waxed = $waxed;
        $this->setChanged();
    }

    public function getEditor() : ?int{
        return $this->editor;
    }

    public function setEditor(?int $runtimeId) : void{
        $this->editor = $runtimeId;
    }

    public function isFrontFacing(BlockState $block, Vector3 $position) : bool{
        if(str_ends_with($block->getName(), "standing_sign")){
            $yaw = deg2rad((int) $block->getProperty(BlockProperties::GROUND_SIGN_DIRECTION) * 22.5);
            [$nx, $nz] = [-sin($yaw), cos($yaw)];
        }else{
            [$nx, , $nz] = Facing::offset((int) $block->getProperty(BlockProperties::FACING_DIRECTION));
        }

        return ($position->x - $this->getX() - 0.5) * $nx + ($position->z - $this->getZ() - 0.5) * $nz > 0;
    }

    public function readData(CompoundTag $nbt) : void{
        foreach(["FrontText", "BackText"] as $side => $name){
            $text = $nbt->getCompoundTag($name);

            if($text !== null){
                $this->text[$side] = $text->getString("Text", "") ?? "";
                $this->owners[$side] = $text->getString("TextOwner", "") ?? "";
                $this->colors[$side] = $text->getInt("SignTextColor", self::DEFAULT_COLOR) ?? self::DEFAULT_COLOR;
                $this->glowing[$side] = ($text->getByte("IgnoreLighting", 0) ?? 0) !== 0;
            }
        }

        $this->waxed = ($nbt->getByte("IsWaxed", 0) ?? 0) !== 0;
    }

    public function writeData(CompoundTag $nbt) : void{
        foreach(["FrontText", "BackText"] as $side => $name){
            $nbt->setTag($name, (new CompoundTag())
                ->setString("Text", $this->text[$side])
                ->setString("TextOwner", $this->owners[$side])
                ->setInt("SignTextColor", $this->colors[$side])
                ->setByte("IgnoreLighting", $this->glowing[$side] ? 1 : 0)
                ->setByte("HideGlowOutline", 0)
                ->setByte("PersistFormatting", 1)
            );
        }

        $nbt->setByte("IsWaxed", $this->waxed ? 1 : 0);
    }
}