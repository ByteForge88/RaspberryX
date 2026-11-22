<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\command\argument\Argument;

use InvalidArgumentException;

use function count;

class ParameterBuilder{

    private int $index;

    private ?string $permission = null;

    /** @var array<int, list<Argument>> */
    private array $slots = [];

    /**
     * @param int $index
     */
    public function __construct(int $index){
        if($index < 0){
            throw new InvalidArgumentException("A parameter builder cannot have a negative index");
        }

        $this->index = $index;
    }

    public function getIndex() : int{
        return $this->index;
    }

    public function addArgument(int $position, Argument $argument) : void{
        if($position < 0){
            throw new InvalidArgumentException("An argument cannot be at a negative position");
        }

        if($position > 0 && !isset($this->slots[$position - 1])){
            throw new InvalidArgumentException("\"{$argument->getName()}\" is at {$position}, but there is nothing at " . ($position - 1));
        }

        foreach($this->slots[$position - 1] ?? [] as $before){
            if($before->takesRestOfLine()){
                throw new InvalidArgumentException("\"{$argument->getName()}\" cannot come after \"{$before->getName()}\", that one takes the rest of the line");
            }

            if($before->isOptional() && !$argument->isOptional()){
                throw new InvalidArgumentException("\"{$argument->getName()}\" is required, so it cannot come after the optional \"{$before->getName()}\"");
            }
        }

        foreach($this->slots as $at => $arguments){
            foreach($arguments as $other){
                if($at !== $position && $other->getName() === $argument->getName()){
                    throw new InvalidArgumentException("There already is an argument named \"{$argument->getName()}\" at {$at}");
                }
            }
        }

        $this->slots[$position][] = $argument;
    }

    /**
     * @return array<int, list<Argument>>
     */
    public function getArguments() : array{
        return $this->slots;
    }

    public function getPermission() : ?string{
        return $this->permission;
    }

    /**
     * A permission the sender needs on top of the command's own. Without it they cannot type it
     * this way, and do not see it in their suggestions either.
     */
    public function setPermission(?string $permission) : void{
        $this->permission = $permission;
    }

    public function canUse(CommandSender $sender) : bool{
        return $this->permission === null || $sender->hasPermission($this->permission);
    }

    /**
     * @return list<list<Argument>>
     */
    public function getOverloads() : array{
        $overloads = [[]];

        for($position = 0, $count = count($this->slots); $position < $count; ++$position){
            $mixed = [];

            foreach($overloads as $overload){
                foreach($this->slots[$position] as $argument){
                    $mixed[] = [...$overload, $argument];
                }
            }

            $overloads = $mixed;
        }

        return $overloads;
    }
}