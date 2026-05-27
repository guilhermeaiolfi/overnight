<?php

declare(strict_types=1);

namespace ON\Mapper\Conversion;

/** Whether a scalar is being read into PHP (inbound) or written out to wire/storage (outbound). */
enum ConversionDirection
{
	case Inbound;
	case Outbound;
}
