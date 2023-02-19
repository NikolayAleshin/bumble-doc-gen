<?php

declare(strict_types=1);

namespace BumbleDocGen\LanguageHandler\Php\Render\Twig\Function;

use BumbleDocGen\LanguageHandler\Php\Parser\Entity\ClassEntity;
use BumbleDocGen\LanguageHandler\Php\Parser\Entity\ClassEntityCollection;
use BumbleDocGen\Render\Context\Context;
use BumbleDocGen\Render\Twig\Filter\HtmlToRst;
use BumbleDocGen\Render\Twig\Function\CustomFunctionInterface;
use BumbleDocGen\Render\Twig\Function\GetDocumentedEntityUrl;

/**
 * Generate class map in HTML or rst format
 *
 * @example {{ drawClassMap(classEntityCollection.filterByPaths(['/BumbleDocGen/Render'])) }}
 * @example {{ drawClassMap(classEntityCollection) }}
 */
final class DrawClassMap implements CustomFunctionInterface
{
    /** @var array<string, string> */
    private array $fileClassmap;

    public function __construct(private Context $context)
    {
    }

    public static function getName(): string
    {
        return 'drawClassMap';
    }

    public static function getOptions(): array
    {
        return [
            'is_safe' => ['html'],
        ];
    }

    /**
     * @param ClassEntityCollection ...$classEntityCollections
     *  The collection of entities for which the class map will be generated
     * @return string
     */
    public function __invoke(ClassEntityCollection ...$classEntityCollections): string
    {
        $structure = $this->convertDirectoryStructureToFormattedString(
            $this->getDirectoryStructure(...$classEntityCollections),
        );

        $content = "<embed> <pre>{$structure}</pre> </embed>";
        if ($this->context->isCurrentTemplateRst()) {
            $htmlToRstFunction = new HtmlToRst();
            return $htmlToRstFunction($content);
        }

        return $content;
    }

    protected function appendClassToDirectoryStructure(array $directoryStructure, ClassEntity $classEntity): array
    {
        $getDocumentedEntityUrl = new GetDocumentedEntityUrl($this->context);
        $this->fileClassmap[$classEntity->getFileName()] = $getDocumentedEntityUrl($classEntity->getName());
        $fileName = ltrim($classEntity->getFileName(), DIRECTORY_SEPARATOR);
        $pathParts = array_reverse(explode(DIRECTORY_SEPARATOR, $fileName));
        $tmpStructure = [array_shift($pathParts)];
        $prevKey = '';
        foreach ($pathParts as $pathPart) {
            $tmpStructure[$pathPart] = $tmpStructure;
            unset($tmpStructure[$prevKey]);
            unset($tmpStructure[0]);
            $prevKey = $pathPart;
        }
        return array_merge_recursive($directoryStructure, $tmpStructure);
    }

    public function getDirectoryStructure(ClassEntityCollection ...$classEntityCollections): array
    {
        $entities = [];
        foreach ($classEntityCollections as $classEntityCollection) {
            foreach ($classEntityCollection as $classEntity) {
                $entities[$classEntity->getName()] = $classEntity;
            }
        }
        ksort($entities, SORT_STRING);
        $directoryStructure = [];
        foreach ($entities as $classEntity) {
            $directoryStructure = $this->appendClassToDirectoryStructure($directoryStructure, $classEntity);
        }
        return $directoryStructure;
    }

    private function sortStruct(array $structure): array
    {
        $sortedStructure = [];
        foreach ($structure as $key => $line) {
            if (is_array($line)) {
                $sortedStructure[$key] = $line;
            }
        }
        foreach ($structure as $key => $line) {
            if (!is_array($line)) {
                $sortedStructure[$key] = $line;
            }
        }
        return $sortedStructure;
    }

    public function convertDirectoryStructureToFormattedString(
        array $structure,
        string $prefix = '│',
        string $path = '/'
    ): string {
        $formattedString = '';
        $elementsCount = count($structure);
        $i = 0;
        $structure = $this->sortStruct($structure);
        foreach ($structure as $key => $line) {
            $isLastLine = ++$i == $elementsCount;
            $preparedPrefix = mb_substr($prefix, 0, -1) . ($isLastLine ? '└' : '├');
            if (is_array($line)) {
                $formattedString .= "{$preparedPrefix}──<b>{$key}</b>/\n";
                $formattedString .= $this->convertDirectoryStructureToFormattedString(
                    $line,
                    "{$prefix}  │",
                    "{$path}{$key}/"
                );
            } else {
                $filepath = "{$path}{$line}";
                $filepath = $this->fileClassmap[$filepath] ?? $filepath;
                $formattedString .= "{$preparedPrefix}── <a href='{$filepath}'>{$line}</a>\n";
            }
        }
        return $formattedString;
    }
}
