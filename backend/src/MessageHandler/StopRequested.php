<?php

declare(strict_types=1);

namespace App\MessageHandler;

/** Unwinds an upload as soon as the operator asks the run to stop. */
final class StopRequested extends \RuntimeException
{
}
