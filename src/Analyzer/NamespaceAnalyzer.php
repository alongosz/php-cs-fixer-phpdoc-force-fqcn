<?php

namespace AdamWojs\PhpCsFixerPhpdocForceFQCN\Analyzer;

use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\Tokenizer\TokensAnalyzer;

final class NamespaceAnalyzer
{
    /** @var \PhpCsFixer\Tokenizer\Tokens */
    private $tokens;

    /**
     * @param \PhpCsFixer\Tokenizer\Tokens $tokens
     */
    public function __construct(Tokens $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * @return \AdamWojs\PhpCsFixerPhpdocForceFQCN\Analyzer\NamespaceInfo[]
     */
    public function getNamespaces(): array
    {
        $namespaces = [];

        $imports = $this->getImportsPerNamespace();

        $this->tokens->rewind();
        foreach ($this->tokens as $index => $token) {
            if (!$token->isGivenKind(T_NAMESPACE)) {
                continue;
            }

            $declarationStartIndex = $index;
            $declarationEndIndex = $this->tokens->getNextTokenOfKind($index, [';', '{']);

            $namespaceName = trim($this->tokens->generatePartialCode(
                $declarationStartIndex + 1,
                $declarationEndIndex - 1
            ));

            $scope = $this->getNamespaceScope($declarationEndIndex);
            $namespaceImports = array_filter($imports, function (ImportInfo $info) use ($scope) {
                return $scope->inRange($info->getDeclaration()->getStartIndex());
            });

            $namespaces[] = new NamespaceInfo(
                $namespaceName,
                $scope,
                $namespaceImports
            );
        }

        return $namespaces;
    }

    /**
     * Based on \PhpCsFixer\Fixer\Import\NoUnusedImportsFixer::getNamespaceUseDeclarations
     *
     * @return \AdamWojs\PhpCsFixerPhpdocForceFQCN\Analyzer\ImportInfo[]
     */
    private function getImportsPerNamespace(): array
    {
        $tokenAnalyzer = new TokensAnalyzer($this->tokens);

        $imports = [];
        foreach ($tokenAnalyzer->getImportUseIndexes() as $declarationStartIndex) {
            $declarationEndIndex = $this->tokens->getNextTokenOfKind($declarationStartIndex, [';', [T_CLOSE_TAG]]);
            $declarationContent = $this->tokens->generatePartialCode($declarationStartIndex + 1, $declarationEndIndex - 1);

            if (false !== strpos($declarationContent, ',')) {
                // ignore multiple use statements that should be split into few separate statements
                // (for example: `use BarB, BarC as C;`)
                continue;
            }

            if (false !== strpos($declarationContent, '{')) {
                // do not touch group use declarations until the logic of this is added
                // (for example: `use some\a\{ClassD};`)
                continue;
            }

            $declarationParts = preg_split('/\s+as\s+/i', $declarationContent);

            if (1 === count($declarationParts)) {
                $fullName = $declarationContent;
                $declarationParts = explode('\\', $fullName);
                $shortName = end($declarationParts);
                $isAliased = false;
            } else {
                list($fullName, $shortName) = $declarationParts;
                $declarationParts = explode('\\', $fullName);
                $isAliased = $shortName !== end($declarationParts);
            }

            $fullName = trim($fullName);
            $shortName = trim($shortName);

            $imports[$shortName] = new ImportInfo(
                $fullName,
                $shortName,
                $isAliased,
                new Range(
                    $declarationStartIndex,
                    $declarationEndIndex
                )
            );
        }

        return $imports;
    }

    /**
     * Returns scope of the namespace.
     *
     * @param int $startIndex Start index of the namespace declaration
     *
     * @return \AdamWojs\PhpCsFixerPhpdocForceFQCN\Analyzer\Range
     */
    private function getNamespaceScope(int $startIndex): Range
    {
        $endIndex = null;
        if ($this->tokens[$startIndex]->isGivenKind('{')) {
            $endIndex = $this->tokens->findBlockEnd(Tokens::BLOCK_TYPE_CURLY_BRACE, $startIndex);
        } else {
            $nextNamespace = $this->tokens->getNextTokenOfKind($startIndex, [T_NAMESPACE]);
            if (!empty($nextNamespace)) {
                $endIndex = $nextNamespace[0];
            }
        }

        return new Range($startIndex, $endIndex);
    }
}
