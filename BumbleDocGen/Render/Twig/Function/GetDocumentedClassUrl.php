<?php

declare(strict_types=1);

namespace BumbleDocGen\Render\Twig\Function;

use BumbleDocGen\Parser\ParserHelper;
use BumbleDocGen\Render\Context\Context;
use BumbleDocGen\Render\Context\DocumentedEntityWrapper;
use BumbleDocGen\Render\Context\DocumentedEntityWrappersCollection;
use BumbleDocGen\Render\Twig\Filter\PrepareSourceLink;

use function PHPUnit\Framework\matches;

/**
 * Get the URL of a documented class by its name. If the class is found, next to the file where this method was called,
 * the `_Classes` directory will be created, in which the documented class file will be created
 *
 * @note This function initiates the creation of documents for the displayed classes
 * @see DocumentedEntityWrapper
 * @see DocumentedEntityWrappersCollection
 * @see Context::$entityWrappersCollection
 *
 * @example {{ getDocumentedClassUrl('\\BumbleDocGen\\Render\\Twig\\MainExtension', 'getFunctions') }}
 * @example {{ getDocumentedClassUrl('\\BumbleDocGen\\Render\\Twig\\MainExtension') }}
 * @example {{ getDocumentedClassUrl('\\BumbleDocGen\\Render\\Twig\\MainExtension', '', false) }}
 */
final class GetDocumentedClassUrl
{
    public const DEFAULT_URL = '#';

    /**
     * @param Context $context Render context
     */
    public function __construct(private Context $context)
    {
    }

    /**
     * @param string $className
     *  The full name of the class for which the URL will be retrieved.
     *  If the class is not found, the DEFAULT_URL value will be returned.
     * @param string $cursor
     *  Cursor on the page of the documented class (for example, the name of a method or property)
     * @param bool $createDocument
     *  If true, creates a class document. Otherwise, just gives a reference to the class code
     *
     * @return string
     */
    public function __invoke(string $className, string $cursor = '', bool $createDocument = true): string
    {
        $reflector = $this->context->getReflector();
        if (ParserHelper::isClassLoaded($reflector, $className)) {
            $classEntity = $this->context->getClassEntityCollection()->getEntityByClassName($className);
            if (!is_null($classEntity) && $createDocument) {
                $documentedClass = new DocumentedEntityWrapper(
                    $classEntity, $this->context->getCurrentTemplateFilePatch()
                );
                $this->context->getEntityWrappersCollection()->add($documentedClass);
                $url = $this->context->getConfiguration()->getOutputDirBaseUrl() . $documentedClass->getDocUrl();
            } else {
                static $urlCaches = [];

                $key = $className . $cursor;
                if (array_key_exists($key, $urlCaches)) {
                    $url = $urlCaches[$key];
                } else {
                    $configuration = $this->context->getConfiguration();
                    $reflection = $reflector->reflectClass($className);
                    $url = $reflection->getFileName() ? str_replace(
                        $configuration->getProjectRoot(),
                        '',
                        $reflection->getFileName()
                    ) : '';

                    if ($url && mb_strlen($cursor) > 2) {
                        $firstLetter = mb_substr($cursor, 0, 1);
                        $cursor = ltrim($cursor, $firstLetter);
                        try {
                            $line = match ($firstLetter) {
                                'm' => $reflection->getMethod($cursor)?->getStartLine(),
                                'p' => $reflection->getProperty($cursor)?->getStartLine(),
                                'q' => $reflection->getReflectionConstant($cursor)?->getStartLine(),
                                default => 0,
                            };
                        } catch (\Exception) {
                            $line = 0;
                        }
                        $url .= $line ? "L{$line}" : '';
                    }
                    $urlCaches[$key] = $url;
                }
            }

            return $url;
        }
        return self::DEFAULT_URL;
    }
}
