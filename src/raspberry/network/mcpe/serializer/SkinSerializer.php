<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\serializer;

use raspberry\entity\Skin;

use raspberry\network\encoding\BinaryWriter;

use function count;

class SkinSerializer{

    private const ARM_SIZE_SLIM = 0;
    private const ARM_SIZE_WIDE = 1;

    public static function write(BinaryWriter $writer, Skin $skin) : void{
        $writer->writeString($skin->skinId);
        $writer->writeString($skin->playFabId);
        $writer->writeString($skin->resourcePatch);
        $writer->writeUnsignedInt($skin->imageWidth);
        $writer->writeUnsignedInt($skin->imageHeight);
        $writer->writeString($skin->imageData);

        $writer->writeUnsignedVarInt(count($skin->animations));

        foreach($skin->animations as $animation){
            $writer->writeUnsignedInt($animation["width"]);
            $writer->writeUnsignedInt($animation["height"]);
            $writer->writeString($animation["data"]);
            $writer->writeUnsignedVarInt($animation["type"]);
            $writer->writeFloat($animation["frames"]);
            $writer->writeUnsignedVarInt($animation["expression"]);
        }

        $writer->writeUnsignedInt($skin->capeWidth);
        $writer->writeUnsignedInt($skin->capeHeight);
        $writer->writeString($skin->capeData);
        $writer->writeString($skin->geometryData);
        $writer->writeString($skin->geometryDataEngineVersion);
        $writer->writeString($skin->animationData);
        $writer->writeString($skin->capeId);
        $writer->writeString($skin->fullId);
        $writer->writeByte($skin->armSize === Skin::ARM_SIZE_SLIM ? self::ARM_SIZE_SLIM : self::ARM_SIZE_WIDE);
        $writer->writeInt(0, false); //skin colour (ARGB, big-endian): not used
        $writer->writeUnsignedVarInt(0); //persona pieces: not kept, see Skin
        $writer->writeUnsignedVarInt(0); //persona piece tint colours
        $writer->writeBool($skin->premium);
        $writer->writeBool($skin->persona);
        $writer->writeBool($skin->capeOnClassicSkin);
        $writer->writeBool(false); //primary user
        $writer->writeBool(true); //override appearance
        $writer->writeString("true"); //trusted: the skin came through Microsoft-authenticated login
        $writer->writeString(""); //profile hash
    }
}