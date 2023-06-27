<?php

declare(strict_types=1);

namespace BumbleDocGen;

use BumbleDocGen\Core\Configuration\Configuration;
use BumbleDocGen\Core\Configuration\Exception\InvalidConfigurationParameterException;
use BumbleDocGen\Core\Parser\Entity\RootEntityCollectionsGroup;
use BumbleDocGen\Core\Parser\ProjectParser;
use BumbleDocGen\Core\Renderer\Renderer;
use BumbleDocGen\Core\Renderer\Twig\Filter\AddIndentFromLeft;
use BumbleDocGen\LanguageHandler\Php\Parser\Entity\ClassEntity;
use BumbleDocGen\LanguageHandler\Php\Parser\Entity\ClassEntityCollection;
use BumbleDocGen\LanguageHandler\Php\Parser\Entity\Exception\ReflectionException;
use BumbleDocGen\LanguageHandler\Php\Parser\ParserHelper;
use BumbleDocGen\TemplateGenerator\ChatGpt\MissingDocBlocksGenerator;
use BumbleDocGen\TemplateGenerator\ChatGpt\TemplatesStructureGenerator;
use DI\DependencyException;
use DI\NotFoundException;
use Monolog\Logger;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Tectalic\OpenAi\ClientException;
use function BumbleDocGen\Core\bites_int_to_string;

/**
 * Class for generating documentation.
 */
final class DocGenerator
{
    public const VERSION = '1.0.0';

    public function __construct(
        private Filesystem                 $fs,
        private OutputStyle                $io,
        private Configuration              $configuration,
        private ProjectParser              $parser,
        private ParserHelper               $parserHelper,
        private Renderer                   $render,
        private RootEntityCollectionsGroup $rootEntityCollectionsGroup,
        private Logger                     $logger
    )
    {
    }

    /**
     * @throws DependencyException
     * @throws NotFoundException
     * @throws InvalidConfigurationParameterException
     */
    public function parseAndGetRootEntityCollectionsGroup(): RootEntityCollectionsGroup
    {
        $this->parser->parse();
        return $this->rootEntityCollectionsGroup;
    }

    /**
     * Generate documentation structure with blank templates using AI tools
     *
     * @throws ClientException
     * @throws NotFoundException
     * @throws ReflectionException
     * @throws DependencyException
     * @throws InvalidConfigurationParameterException
     */
    public function generateProjectTemplatesStructure(): void
    {
        $this->parser->parse();
        $entitiesCollection = $this->rootEntityCollectionsGroup->get(ClassEntityCollection::getEntityCollectionName());

        $openaiKey = getenv('OPENAI_API_KEY') ?: $this->io->askHidden('Enter the key to work with ChatGpt');
        $openaiClient = \Tectalic\OpenAi\Manager::build(
            new \GuzzleHttp\Client(),
            new \Tectalic\OpenAi\Authentication($openaiKey)
        );

        $availableModels = array_values(array_filter(
            array_map(
                fn(array $v) => $v['id'],
                $openaiClient->models()->list()->toArray()['data'] ?? []
            ),
            fn(string $v) => str_starts_with($v, "gpt-")
        ));

        $model = $this->io->choice("Choose GPT model from available", $availableModels);
        $templatesStructureGenerator = new TemplatesStructureGenerator($openaiClient, $model);

        do {
            $additionalPrompt = $this->io->ask('Write instructions for more accurate documentation generation ( or just skip this step )') ?: null;
            $this->logger->notice("Sending ChatGPT request");
            $structure = $templatesStructureGenerator->generateStructureByEntityCollection($entitiesCollection, $additionalPrompt);
            $structureAsString = implode("\n", array_map(fn($v, $k) => "{$k} => {$v}", $structure, array_keys($structure)));
            $action = $this->io->choice("The proposed documentation structure is as follows:\n\n{$structureAsString}", ['Save', 'Regenerate', 'Cancel']);
        } while ($action == 'Regenerate');

        if ($action === 'Save') {
            $templatesDir = $this->configuration->getTemplatesDir();
            $finder = new Finder();
            $finder->files()->in($this->configuration->getTemplatesDir());
            if (
                $finder->hasResults() &&
                $this->io->confirm("Directory `{$templatesDir}` already contains files. Clean before saving new ones?")
            ) {
                $this->fs->remove([$templatesDir]);
            }

            foreach ($structure as $fileName => $title) {
                $fileName = $templatesDir . $fileName;
                $this->fs->appendToFile($fileName, "{% set title = '{$title}' %}\n");
                if (!str_ends_with($fileName, 'readme.md.twig')) {
                    $this->fs->appendToFile($fileName, "{{ generatePageBreadcrumbs(title, _self) }}\n");
                }
                $this->logger->notice("Creating `{$fileName}` template");
            }
        }
    }

    /**
     * @throws NotFoundException
     * @throws DependencyException
     * @throws ReflectionException
     * @throws InvalidConfigurationParameterException
     * @throws ClientException
     */
    public function addMissingDocBlocks(): void
    {
        if (!$this->io->confirm("This command will change the source code of your project. Continue?")) {
            return;
        }

        $this->parser->parse();
        $entitiesCollection = $this->rootEntityCollectionsGroup->get(ClassEntityCollection::getEntityCollectionName());

        $openaiKey = getenv('OPENAI_API_KEY') ?: $this->io->askHidden('Enter the key to work with ChatGpt');
        $openaiClient = \Tectalic\OpenAi\Manager::build(
            new \GuzzleHttp\Client(),
            new \Tectalic\OpenAi\Authentication($openaiKey)
        );

        $availableModels = array_values(array_filter(
            array_map(
                fn(array $v) => $v['id'],
                $openaiClient->models()->list()->toArray()['data'] ?? []
            ),
            fn(string $v) => str_starts_with($v, "gpt-")
        ));

        $model = $this->io->choice("Choose GPT model from available", $availableModels);
        $missingDocBlocksGenerator = new MissingDocBlocksGenerator($openaiClient, $this->parserHelper, $model);
        foreach ($entitiesCollection as $entity) {
            /**@var ClassEntity $entity */
            if (!$missingDocBlocksGenerator->hasMethodsWithoutDocBlocks($entity)) {
                $this->logger->notice("Skipping `{$entity->getName()}`class. All methods are already documented");
            }
            if (!$this->io->confirm("Start processing class `{$entity->getName()}`? Choose `no` to skip")) {
                continue;
            }
            $this->logger->notice("Processing `{$entity->getName()}` class");
            $newBocBlocks = $missingDocBlocksGenerator->generateDocBlocksForMethodsWithoutIt($entity);

            $classFileContent = $entity->getFileContent();
            $toReplace = [];
            $classFileLines = explode("\n", $classFileContent);
            foreach ($newBocBlocks as $method => $docBlock) {
                $methodEntity = $entity->getMethodEntity($method);
                $lineNumber = $docCommentLine = $methodEntity->getDocComment() ? $methodEntity->getDocBlock(false)->getLocation()?->getLineNumber() : null;
                $lineNumber = $lineNumber ?: $methodEntity->getStartLine();

                foreach (file($entity->getFullFileName(), FILE_IGNORE_NEW_LINES) as $line => $lineContent) {
                    if ($line + 1 === $lineNumber) {
                        $classFileLines[$line] = "[%docBlock%{$method}%]{$lineContent}";
                        break;
                    }
                }
                $docBlock = (new AddIndentFromLeft())->__invoke($docBlock, $methodEntity->getStartColumn() - 1);
                $toReplace["/(?:\[%docBlock%{$method}%\] *\/\*\*.*?(?= *\/)\/)|\[%docBlock%{$method}%\]/s"] = $docBlock . ($docCommentLine ? '' : "\n");
            }

            $classFileContent = implode("\n", $classFileLines);
            $classFileContent = preg_replace(array_keys($toReplace), $toReplace, $classFileContent);
            file_put_contents($entity->getFullFileName(), $classFileContent);
            $this->logger->notice("DocBlocks added");
        }
    }

    /**
     * Generates documentation using configuration
     *
     * @throws InvalidArgumentException
     */
    public function generate(): void
    {
        $start = microtime(true);
        $memory = memory_get_usage();

        try {
            $this->parser->parse();
            $this->render->run();
        } catch (\Exception $e) {
            $this->logger->critical("{$e->getFile()}:{$e->getLine()} {$e->getMessage()} \n\n{{$e->getTraceAsString()}}");
        }

        $time = microtime(true) - $start;
        $this->logger->notice("Time of execution: {$time} sec.");
        $memory = memory_get_usage() - $memory;
        $this->logger->notice('Memory:' . bites_int_to_string($memory));
    }
}
