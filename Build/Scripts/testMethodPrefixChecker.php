<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\Finder;

require_once __DIR__ . '/../../.Build/vendor/autoload.php';

/**
 * This script checks that test methods do not start with "test", for example
 * "public function testSomething()". Instead they should be named like
 * "public function somethingIsLike()" and carry the #[Test] attribute.
 */
class NodeVisitor extends NodeVisitorAbstract
{
    /**
     * @var array<int, string>
     */
    public array $matches = [];

    public function enterNode(Node $node): void
    {
        if (($node instanceof Node\Stmt\ClassMethod) && str_starts_with($node->name->name, 'test')) {
            $this->matches[$node->getLine()] = $node->name->name;
        }
    }
}

// php-parser spans two majors on this branch: "typo3/cms-install" 12.4 requires
// "^4.15.4", so a TYPO3 v12 dependency set installs php-parser 4, and a v13 one
// installs 5. This is a build script and not extension code, so a conditional is
// the right tool here - there is no container to pick an implementation with.
//
// "PhpVersion" is the class php-parser 5 added along with "createForVersion()",
// which makes it the honest thing to test for - "::class" does not autoload, so
// this asks whether the class exists rather than loading it.
// @todo Collapse to the "createForVersion()" branch when TYPO3 v12 support is
//       dropped and php-parser 4 goes with it.
$parser = class_exists(PhpVersion::class)
    ? (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2))
    : (new ParserFactory())->create(ParserFactory::PREFER_PHP7);

$finder = new Finder();
$finder->files()
    ->in([
        __DIR__ . '/../../Tests/Unit/',
        __DIR__ . '/../../Tests/Functional/',
    ])
    ->name('/Test\.php$/');

$output = new ConsoleOutput();

$errors = [];
foreach ($finder as $file) {
    try {
        $ast = $parser->parse($file->getContents());
    } catch (\PhpParser\Error $error) {
        $output->writeln('<error>Parse error: ' . $error->getMessage() . '</error>');
        exit(1);
    }

    $visitor = new NodeVisitor();

    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast ?? []);

    if ($visitor->matches !== []) {
        $errors[$file->getRealPath()] = $visitor->matches;
        $output->write('<error>F</error>');
    } else {
        $output->write('<fg=green>.</>');
    }
}

$output->writeln('');

if ($errors !== []) {
    $output->writeln('');

    foreach ($errors as $file => $matchesPerLine) {
        $output->writeln('');
        $output->writeln('<error>At least one method starts with "test" in ' . $file . '</error>');

        foreach ($matchesPerLine as $line => $methodName) {
            $output->writeln('Method: ' . $methodName . ' Line: ' . $line);
        }
    }
    exit(1);
}

exit(0);
