<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpResourceTemplate;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Completion\EnumCompletionProvider;
use Mcp\Capability\Completion\ListCompletionProvider;
use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ExceptionInterface;
use Mcp\Exception\RuntimeException;
use Mcp\Schema\Prompt;
use Mcp\Schema\PromptArgument;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * @internal
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Discoverer implements DiscovererInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        private ?DocBlockParser $docBlockParser = null,
        private ?SchemaGeneratorInterface $schemaGenerator = null,
    ) {
        if (!class_exists(Finder::class)) {
            throw new RuntimeException('File-based discovery requires symfony/finder. Run: composer require symfony/finder');
        }

        $this->docBlockParser = $docBlockParser ?? new DocBlockParser(logger: $this->logger);
        $this->schemaGenerator = $schemaGenerator ?? new SchemaGenerator($this->docBlockParser);
    }

    /**
     * Discover MCP elements in the specified directories and return the discovery state.
     *
     * @param string        $basePath     the base path for resolving directories
     * @param array<string> $directories  list of directories (relative to base path) to scan
     * @param array<string> $excludeDirs  list of directories (relative to base path) to exclude from the scan
     * @param array<string> $namePatterns list of file name patterns for the scan. Compatible with Finder->name()
     */
    public function discover(string $basePath, array $directories, array $excludeDirs = [], array $namePatterns = self::DEFAULT_NAME_PATERNS): DiscoveryState
    {
        $startTime = microtime(true);

        $namePatterns = !empty($namePatterns) ? $namePatterns : self::DEFAULT_NAME_PATERNS;

        $state = new DiscoveryState();

        try {
            $finder = new Finder();
            $absolutePaths = [];
            foreach ($directories as $dir) {
                $path = rtrim($basePath, '/').'/'.ltrim($dir, '/');
                if (is_dir($path)) {
                    $absolutePaths[] = $path;
                }
            }

            if (empty($absolutePaths)) {
                $this->logger->warning('No valid discovery directories found to scan.', [
                    'configured_paths' => $directories,
                    'base_path' => $basePath,
                ]);

                return new DiscoveryState();
            }

            $finder->files()
                ->in($absolutePaths)
                ->exclude($excludeDirs)
                ->name($namePatterns);

            foreach ($finder as $file) {
                $state = $this->processFile($file, $state);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Error during file finding process for MCP discovery'.json_encode($e->getTrace(), \JSON_PRETTY_PRINT), [
                'exception' => $e,
            ]);
        }

        $duration = microtime(true) - $startTime;
        $this->logger->info('Attribute discovery finished.', ['duration_sec' => round($duration, 3)] + $state->getElementCounts());

        return $state;
    }

    /**
     * Process a single PHP file for MCP elements on classes or methods.
     *
     * Returns the given discovery state extended by the elements found in the file.
     */
    private function processFile(SplFileInfo $file, DiscoveryState $state): DiscoveryState
    {
        $className = $this->getClassFromFile($file);
        if (!$className) {
            $this->logger->warning('No valid class found in file', ['file' => $file->getPathname()]);

            return $state;
        }

        try {
            $reflectionClass = new \ReflectionClass($className);

            if ($reflectionClass->isAbstract() || $reflectionClass->isInterface() || $reflectionClass->isTrait() || $reflectionClass->isEnum()) {
                return $state;
            }

            $processedViaClassAttribute = false;
            if ($reflectionClass->hasMethod('__invoke')) {
                $invokeMethod = $reflectionClass->getMethod('__invoke');
                if ($invokeMethod->isPublic() && !$invokeMethod->isStatic()) {
                    $attributeTypes = [McpTool::class, McpResource::class, McpPrompt::class, McpResourceTemplate::class];
                    foreach ($attributeTypes as $attributeType) {
                        $classAttribute = $reflectionClass->getAttributes($attributeType, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
                        if ($classAttribute) {
                            if ($reference = $this->processMethod($invokeMethod, $classAttribute)) {
                                $state = $state->add($reference);
                            }
                            $processedViaClassAttribute = true;
                            break;
                        }
                    }
                }
            }

            if (!$processedViaClassAttribute) {
                foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    if (
                        $method->getDeclaringClass()->getName() !== $reflectionClass->getName()
                        || $method->isStatic() || $method->isAbstract() || $method->isConstructor() || $method->isDestructor() || '__invoke' === $method->getName()
                    ) {
                        continue;
                    }
                    $attributeTypes = [McpTool::class, McpResource::class, McpPrompt::class, McpResourceTemplate::class];
                    foreach ($attributeTypes as $attributeType) {
                        $methodAttribute = $method->getAttributes($attributeType, \ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
                        if ($methodAttribute) {
                            if ($reference = $this->processMethod($method, $methodAttribute)) {
                                $state = $state->add($reference);
                            }
                            break;
                        }
                    }
                }
            }
        } catch (\ReflectionException $e) {
            $this->logger->error('Reflection error processing file for MCP discovery', ['file' => $file->getPathname(), 'class' => $className, 'exception' => $e]);
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error processing file for MCP discovery', [
                'file' => $file->getPathname(),
                'class' => $className,
                'exception' => $e,
            ]);
        }

        return $state;
    }

    /**
     * Build the element reference for a method carrying a given MCP attribute.
     * Can be called for regular methods or the __invoke method of an invokable class.
     *
     * @param \ReflectionMethod                                                       $method    The target method (e.g., regular method or __invoke).
     * @param \ReflectionAttribute<McpTool|McpResource|McpPrompt|McpResourceTemplate> $attribute the ReflectionAttribute instance found (on method or class)
     *
     * @return ToolReference|ResourceReference|PromptReference|ResourceTemplateReference|null the discovered element, or null if the attribute could not be processed
     */
    private function processMethod(\ReflectionMethod $method, \ReflectionAttribute $attribute): ToolReference|ResourceReference|PromptReference|ResourceTemplateReference|null
    {
        try {
            $instance = $attribute->newInstance();

            return match (true) {
                $instance instanceof McpTool => $this->buildTool($method, $instance),
                $instance instanceof McpResource => $this->buildResource($method, $instance),
                $instance instanceof McpPrompt => $this->buildPrompt($method, $instance),
                $instance instanceof McpResourceTemplate => $this->buildResourceTemplate($method, $instance),
            };
        } catch (ExceptionInterface $e) {
            $this->logger->error(\sprintf('Failed to process MCP attribute on %s::%s', $method->getDeclaringClass()->getName(), $method->getName()), [
                'attribute' => $attribute->getName(),
                'exception' => $e,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Unexpected error processing attribute on %s::%s', $method->getDeclaringClass()->getName(), $method->getName()), [
                'attribute' => $attribute->getName(),
                'exception' => $e,
            ]);
        }

        return null;
    }

    private function buildTool(\ReflectionMethod $method, McpTool $attribute): ToolReference
    {
        $docBlock = $this->docBlockParser->parseDocBlock($method->getDocComment() ?? null);
        $tool = new Tool(
            name: $attribute->name ?? $this->defaultName($method),
            title: $attribute->title,
            inputSchema: $this->schemaGenerator->generate($method),
            description: $attribute->description ?? $this->docBlockParser->getDescription($docBlock),
            annotations: $attribute->annotations,
            icons: $attribute->icons,
            meta: $attribute->meta,
            outputSchema: $this->schemaGenerator->generateOutputSchema($method),
        );

        return new ToolReference($tool, [$method->getDeclaringClass()->getName(), $method->getName()]);
    }

    private function buildResource(\ReflectionMethod $method, McpResource $attribute): ResourceReference
    {
        $docBlock = $this->docBlockParser->parseDocBlock($method->getDocComment() ?? null);
        $resource = new ResourceDefinition(
            $attribute->uri,
            $attribute->name ?? $this->defaultName($method),
            $attribute->title,
            $attribute->description ?? $this->docBlockParser->getDescription($docBlock),
            $attribute->mimeType,
            $attribute->annotations,
            $attribute->size,
            $attribute->icons,
            $attribute->meta,
        );

        return new ResourceReference($resource, [$method->getDeclaringClass()->getName(), $method->getName()]);
    }

    private function buildPrompt(\ReflectionMethod $method, McpPrompt $attribute): PromptReference
    {
        $docBlock = $this->docBlockParser->parseDocBlock($method->getDocComment() ?? null);
        $arguments = [];
        $paramTags = $this->docBlockParser->getParamTags($docBlock);
        foreach ($method->getParameters() as $param) {
            $reflectionType = $param->getType();
            if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                continue;
            }
            $paramTag = $paramTags['$'.$param->getName()] ?? null;
            $arguments[] = new PromptArgument($param->getName(), $paramTag ? trim((string) $paramTag->getDescription()) : null, !$param->isOptional() && !$param->isDefaultValueAvailable());
        }
        $prompt = new Prompt(
            $attribute->name ?? $this->defaultName($method),
            $attribute->title,
            $attribute->description ?? $this->docBlockParser->getDescription($docBlock),
            $arguments,
            $attribute->icons,
            $attribute->meta,
        );

        return new PromptReference($prompt, [$method->getDeclaringClass()->getName(), $method->getName()], $this->getCompletionProviders($method));
    }

    private function buildResourceTemplate(\ReflectionMethod $method, McpResourceTemplate $attribute): ResourceTemplateReference
    {
        $docBlock = $this->docBlockParser->parseDocBlock($method->getDocComment() ?? null);
        $resourceTemplate = new ResourceTemplate(
            $attribute->uriTemplate,
            $attribute->name ?? $this->defaultName($method),
            $attribute->title,
            $attribute->description ?? $this->docBlockParser->getDescription($docBlock),
            $attribute->mimeType,
            $attribute->annotations,
            $attribute->meta,
        );

        return new ResourceTemplateReference($resourceTemplate, [$method->getDeclaringClass()->getName(), $method->getName()], $this->getCompletionProviders($method));
    }

    /**
     * Fall back to the method name, or the class short name for invokable classes.
     */
    private function defaultName(\ReflectionMethod $method): string
    {
        return '__invoke' === $method->getName() ? $method->getDeclaringClass()->getShortName() : $method->getName();
    }

    /**
     * @return array<string, string|ProviderInterface>
     */
    private function getCompletionProviders(\ReflectionMethod $reflectionMethod): array
    {
        $completionProviders = [];
        foreach ($reflectionMethod->getParameters() as $param) {
            $reflectionType = $param->getType();
            if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                continue;
            }

            $completionAttributes = $param->getAttributes(CompletionProvider::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($completionAttributes)) {
                $attributeInstance = $completionAttributes[0]->newInstance();

                if ($attributeInstance->provider) {
                    $completionProviders[$param->getName()] = $attributeInstance->provider;
                } elseif ($attributeInstance->providerClass) {
                    $completionProviders[$param->getName()] = $attributeInstance->provider;
                } elseif ($attributeInstance->values) {
                    $completionProviders[$param->getName()] = new ListCompletionProvider($attributeInstance->values);
                } elseif ($attributeInstance->enum) {
                    $completionProviders[$param->getName()] = new EnumCompletionProvider($attributeInstance->enum);
                }
            }
        }

        return $completionProviders;
    }

    /**
     * Attempt to determine the FQCN from a PHP file path.
     * Uses tokenization to extract namespace and class name.
     *
     * @return class-string|null the FQCN or null if not found/determinable
     */
    private function getClassFromFile(SplFileInfo $file): ?string
    {
        $this->logger->debug('Processing file', ['path' => $file->getPathname()]);

        try {
            $content = $file->getContents();
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to read file content during class discovery: {$file->getPathname()}", [
                'exception' => $e,
            ]);

            return null;
        }

        if (\strlen($content) > 500 * 1024) {
            $this->logger->warning('Skipping large file during class discovery.', ['file' => $file->getPathname()]);

            return null;
        }

        try {
            $tokens = token_get_all($content);
        } catch (\Throwable $e) {
            $this->logger->warning("Failed to tokenize file during class discovery: {$file->getPathname()}", [
                'exception' => $e,
            ]);

            return null;
        }

        $namespace = '';
        $namespaceFound = false;
        $level = 0;
        $potentialClasses = [];

        $tokenCount = \count($tokens);
        for ($i = 0; $i < $tokenCount; ++$i) {
            if (\is_array($tokens[$i]) && \T_NAMESPACE === $tokens[$i][0]) {
                $namespace = '';
                for ($j = $i + 1; $j < $tokenCount; ++$j) {
                    if (';' === $tokens[$j] || '{' === $tokens[$j]) {
                        $namespaceFound = true;
                        $i = $j;
                        break;
                    }
                    if (\is_array($tokens[$j]) && \in_array($tokens[$j][0], [\T_STRING, \T_NAME_QUALIFIED])) {
                        $namespace .= $tokens[$j][1];
                    } elseif (\T_NS_SEPARATOR === $tokens[$j][0]) {
                        $namespace .= '\\';
                    }
                }
                if ($namespaceFound) {
                    break;
                }
            }
        }
        $namespace = trim($namespace, '\\');

        for ($i = 0; $i < $tokenCount; ++$i) {
            $token = $tokens[$i];
            if ('{' === $token) {
                ++$level;

                continue;
            }
            if ('}' === $token) {
                --$level;

                continue;
            }

            if ($level === ($namespaceFound && str_contains($content, "namespace {$namespace} {") ? 1 : 0)) {
                if (\is_array($token) && \in_array($token[0], [\T_CLASS, \T_INTERFACE, \T_TRAIT, \defined('T_ENUM') ? \T_ENUM : -1])) {
                    for ($j = $i + 1; $j < $tokenCount; ++$j) {
                        if (\is_array($tokens[$j]) && \T_STRING === $tokens[$j][0]) {
                            $className = $tokens[$j][1];
                            $potentialClasses[] = $namespace ? $namespace.'\\'.$className : $className;
                            $i = $j;
                            break;
                        }
                        if (';' === $tokens[$j] || '{' === $tokens[$j] || ')' === $tokens[$j]) {
                            break;
                        }
                    }
                }
            }
        }

        foreach ($potentialClasses as $potentialClass) {
            if (class_exists($potentialClass, true)) {
                return $potentialClass;
            }
        }

        if (!empty($potentialClasses)) {
            if (!class_exists($potentialClasses[0], false)) {
                $this->logger->debug('getClassFromFile returning potential non-class type. Are you sure this class has been autoloaded?', ['file' => $file->getPathname(), 'type' => $potentialClasses[0]]);
            }

            return $potentialClasses[0];
        }

        return null;
    }
}
