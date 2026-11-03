<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\recipe\CraftingRecipeData;
use raspberry\network\mcpe\protocol\types\recipe\SmithingRecipeData;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use UnexpectedValueException;

use function count;

class CraftingDataPacket implements Packet{

    /** @var list<CraftingRecipeData> */
    public array $shaped = [];

    /** @var list<CraftingRecipeData> */
    public array $shapeless = [];

    /** @var list<SmithingRecipeData> */
    public array $smithingTransform = [];

    /** @var list<SmithingRecipeData> */
    public array $smithingTrim = [];

    /** @var list<array{int, int, int, int, int, int}> */
    public array $potionRecipes = [];

    /** @var list<array{int, int, int}> */
    public array $potionContainerRecipes = [];

    /** forget recipes from before (always true, RaspberryX sends everything at once) */
    public bool $clearRecipes = true;

    public static function getPacketId() : int{
        return ProtocolPacketIds::CRAFTING_DATA_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(count($this->shaped));

        foreach($this->shaped as $recipe){
            $writer->writeString($recipe->id);
            $writer->writeVarInt($recipe->width);
            $writer->writeVarInt($recipe->height);
            $this->writeRecipeBody($writer, $recipe);
        }

        $writer->writeUnsignedVarInt(count($this->shapeless));

        foreach($this->shapeless as $recipe){
            $writer->writeString($recipe->id);
            $this->writeRecipeBody($writer, $recipe);
        }

        for($i = 0; $i < 4; ++$i){
            $writer->writeUnsignedVarInt(0);
        }

        $writer->writeUnsignedVarInt(count($this->smithingTransform));

        foreach($this->smithingTransform as $recipe){
            $this->writeSmithing($writer, $recipe);
        }

        $writer->writeUnsignedVarInt(count($this->smithingTrim));

        foreach($this->smithingTrim as $recipe){
            $this->writeSmithing($writer, $recipe);
        }

        $writer->writeUnsignedVarInt(count($this->potionRecipes));

        foreach($this->potionRecipes as $recipe){
            foreach($recipe as $value){
                $writer->writeVarInt($value);
            }
        }

        $writer->writeUnsignedVarInt(count($this->potionContainerRecipes));

        foreach($this->potionContainerRecipes as $recipe){
            foreach($recipe as $value){
                $writer->writeVarInt($value);
            }
        }

        $writer->writeUnsignedVarInt(0); //material reducers
        $writer->writeBool($this->clearRecipes);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("CraftingDataPacket is server-to-client only");
    }

    private function writeRecipeBody(BinaryWriter $writer, CraftingRecipeData $recipe) : void{
        $writer->writeUnsignedVarInt(count($recipe->input));

        foreach($recipe->input as $descriptor){
            $descriptor->write($writer);
        }

        $writer->writeUnsignedVarInt(count($recipe->output));

        foreach($recipe->output as $item){
            ItemStackSerializer::writeStack($writer, $item);
        }

        $writer->writeUuid($recipe->uuid);
        $writer->writeString($recipe->block);
        $writer->writeVarInt($recipe->priority);

        if($recipe->isShaped()){
            $writer->writeBool(true); //mirrored patterns match too
        }

        $writer->writeBool($recipe->unlockContext !== null);

        if($recipe->unlockContext !== null){
            $writer->writeVarInt($recipe->unlockContext);
            $writer->writeBool($recipe->unlockContext === 0);

            if($recipe->unlockContext === 0){
                $writer->writeUnsignedVarInt(count($recipe->unlockItems));

                foreach($recipe->unlockItems as $descriptor){
                    $descriptor->write($writer);
                }
            }
        }

        $writer->writeUnsignedVarInt($recipe->networkId);
    }

    private function writeSmithing(BinaryWriter $writer, SmithingRecipeData $recipe) : void{
        $writer->writeString($recipe->id);
        $recipe->template->write($writer);
        $recipe->base->write($writer);
        $recipe->addition->write($writer);

        if($recipe->output !== null){
            ItemStackSerializer::writeStack($writer, $recipe->output);
        }

        $writer->writeString($recipe->block);
        $writer->writeUnsignedVarInt($recipe->networkId);
    }
}