<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\command\CommandOriginData;
use raspberry\network\mcpe\protocol\types\command\CommandOutputMessage;

use function count;

class CommandOutputPacket implements Packet{

    public const string TYPE_NONE = "none";
    public const string TYPE_LAST = "lastoutput";
    public const string TYPE_SILENT = "silent";
    public const string TYPE_ALL = "alloutput";
    public const string TYPE_DATA_SET = "dataset";

    public CommandOriginData $origin;

    public string $outputType = self::TYPE_ALL;

    public int $successCount = 0;

    /** @var list<CommandOutputMessage> */
    public array $messages = [];

    public ?string $data = null;

    /**
     * @param list<CommandOutputMessage> $messages
     */
    public static function create(CommandOriginData $origin, int $successCount, array $messages) : self{
        $packet = new self();
        $packet->origin = $origin;
        $packet->successCount = $successCount;
        $packet->messages = $messages;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::COMMAND_OUTPUT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $this->origin->write($writer);
        $writer->writeString($this->outputType, 64);
        $writer->writeUnsignedInt($this->successCount);
        $writer->writeUnsignedVarInt(count($this->messages));

        foreach($this->messages as $message){
            $writer->writeString($message->messageId);
            $writer->writeBool($message->success);
            $writer->writeUnsignedVarInt(count($message->parameters));

            foreach($message->parameters as $parameter){
                $writer->writeString($parameter);
            }
        }

        $writer->writeBool($this->data !== null);

        if($this->data !== null){
            $writer->writeString($this->data);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->origin = CommandOriginData::read($reader);
        $this->outputType = $reader->readString(64);
        $this->successCount = $reader->readUnsignedInt();
        $this->messages = [];

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $messageId = $reader->readString();
            $success = $reader->readBool();
            $parameters = [];

            for($j = 0, $parameterCount = $reader->readUnsignedVarInt(); $j < $parameterCount; ++$j){
                $parameters[] = $reader->readString();
            }

            $this->messages[] = new CommandOutputMessage($messageId, $success, $parameters);
        }

        $this->data = $reader->readBool() ? $reader->readString() : null;
    }
}