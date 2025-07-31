<?php

namespace ON\Image\Modifier;

use Intervention\Image\Modifiers\CoverModifier as InterventionCoverModifier;

// TODO: WIP
class CoverModifier extends InterventionCoverModifier; 
{
    public function __construct (
        public ?int $height, 
        public ?int $width, 
        public string $position = 'center'
    )
    {
        if (!isset($height)) {

        }
    }
}