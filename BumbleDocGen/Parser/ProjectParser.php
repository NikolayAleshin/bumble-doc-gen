<?php

declare(strict_types=1);

namespace BumbleDocGen\Parser;

use BumbleDocGen\ConfigurationInterface;
use BumbleDocGen\LanguageHandler\Php\Parser\Entity\ClassEntityCollection;
use BumbleDocGen\LanguageHandler\Php\PhpHandler;
use BumbleDocGen\Plugin\PluginEventDispatcher;

/**
 * Class for project parsing using source locators
 */
final class ProjectParser
{
    private function __construct(
        private ConfigurationInterface $configuration,
        private PluginEventDispatcher  $pluginEventDispatcher
    )
    {
    }

    public static function create(
        ConfigurationInterface $configuration,
        PluginEventDispatcher  $pluginEventDispatcher
    ): ProjectParser
    {
        return new self($configuration, $pluginEventDispatcher);
    }

    public function parse(): ClassEntityCollection
    {
        return $this->configuration->getLanguageHandlersCollection(
            $this->pluginEventDispatcher
        )->get(PhpHandler::class)->getEntityCollection();
    }
}
