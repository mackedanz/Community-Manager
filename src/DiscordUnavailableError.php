<?php

declare(strict_types=1);

namespace Hangar;

/** Discord ist nicht erreichbar oder drosselt: später erneut versuchen. */
final class DiscordUnavailableError extends \RuntimeException
{
}
