<?php

declare(strict_types=1);

namespace ON\Init;

enum InitStage: string
{
	case Before = 'before';
	case On = 'on';
	case After = 'after';
}
