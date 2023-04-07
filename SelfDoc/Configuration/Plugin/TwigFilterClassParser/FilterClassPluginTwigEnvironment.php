<?php

declare(strict_types=1);

namespace SelfDoc\Configuration\Plugin\TwigFilterClassParser;

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;

final class FilterClassPluginTwigEnvironment
{
    private Environment $twig;

    public function __construct()
    {
        $loader = new FilesystemLoader([
            __DIR__ . '/templates',
        ]);
        $this->twig = new Environment($loader);
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    public function render($name, array $context = []): string
    {
        return $this->twig->render($name, $context);
    }
}
