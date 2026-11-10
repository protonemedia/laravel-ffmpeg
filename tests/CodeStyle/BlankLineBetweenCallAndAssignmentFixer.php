<?php

namespace ProtoneMedia\LaravelFFMpeg\Tests\CodeStyle;

use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;
use PhpCsFixer\WhitespacesFixerConfig;
use SplFileInfo;

class BlankLineBetweenCallAndAssignmentFixer implements WhitespacesAwareFixerInterface
{
    public const NAME = 'ProtoneMedia/blank_line_between_call_and_assignment';

    /**
     * The tokens that turn a statement into an assignment.
     */
    protected const ASSIGNMENTS = [
        '=', [T_PLUS_EQUAL], [T_MINUS_EQUAL], [T_MUL_EQUAL], [T_DIV_EQUAL], [T_CONCAT_EQUAL],
        [T_MOD_EQUAL], [T_AND_EQUAL], [T_OR_EQUAL], [T_XOR_EQUAL], [T_SL_EQUAL], [T_SR_EQUAL],
        [T_POW_EQUAL], [T_COALESCE_EQUAL],
    ];

    /**
     * The tokens a call or an assignment statement may start with.
     */
    protected const STARTS = [
        T_VARIABLE, T_STRING, T_STATIC, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE, T_LIST, CT::T_DESTRUCTURING_SQUARE_BRACE_OPEN,
    ];

    /**
     * The tokens a chain of calls is made of, besides the argument lists.
     */
    protected const CHAIN = [
        T_VARIABLE, T_STRING, T_STATIC, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED,
        T_NAME_RELATIVE, T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR,
    ];

    /**
     * The whitespace configuration of the current run.
     */
    protected WhitespacesFixerConfig $whitespaces;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'A standalone call and an assignment next to it must be separated by a blank line.',
            [new CodeSample("<?php\n\$user = User::find(1);\n\$user->touch();\n\$name = \$user->name;\n")],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(';');
    }

    public function isRisky(): bool
    {
        return false;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function supports(SplFileInfo $file): bool
    {
        return true;
    }

    public function setWhitespacesConfig(WhitespacesFixerConfig $config): void
    {
        $this->whitespaces = $config;
    }

    public function fix(SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = 0; $index < $tokens->count(); $index++) {
            if (! $tokens[$index]->equals(';') || ! ($next = $tokens->getNextMeaningfulToken($index))) {
                continue;
            }

            $previous = $this->type($tokens, $this->statementStart($tokens, $index), $index);
            $current = $this->type($tokens, $next, $this->statementEnd($tokens, $next));

            if ($previous !== null && $current !== null && $previous !== $current) {
                $this->ensureBlankLine($tokens, $index, $next);
            }
        }
    }

    /**
     * Find the first token of the statement that ends at the given semicolon.
     */
    protected function statementStart(Tokens $tokens, int $semicolon): ?int
    {
        $start = $semicolon;

        for ($index = $tokens->getPrevMeaningfulToken($semicolon); $index !== null; $index = $tokens->getPrevMeaningfulToken($index)) {
            $token = $tokens[$index];

            if ($token->equalsAny([';', [T_OPEN_TAG], [T_CLOSE_TAG]])) {
                return $start;
            }

            // A closing brace followed by the start of a statement ends the block before
            // it, such as an if statement. Any other closing brace belongs to a closure,
            // a match or an anonymous class inside the statement, so we skip over it.
            if ($token->equals('}') && $this->startsStatement($tokens, $start)) {
                return $start;
            }

            if ($block = Tokens::detectBlockType($token)) {
                // Reaching an opening token means the semicolon is inside it, which is a
                // block statement for a brace and a for loop header for a parenthesis.
                if ($block['isStart']) {
                    return $token->equals('{') ? $start : null;
                }

                $index = $tokens->findBlockStart($block['type'], $index);
            }

            $start = $index;
        }

        return null;
    }

    /**
     * Find the semicolon that ends the statement starting at the given token.
     */
    protected function statementEnd(Tokens $tokens, int $start): ?int
    {
        if (! $this->startsStatement($tokens, $start)) {
            return null;
        }

        for ($index = $start; $index !== null; $index = $tokens->getNextMeaningfulToken($index)) {
            if ($tokens[$index]->equals(';')) {
                return $index;
            }

            if ($block = Tokens::detectBlockType($tokens[$index])) {
                if (! $block['isStart']) {
                    return null;
                }

                $index = $tokens->findBlockEnd($block['type'], $index);
            }
        }

        return null;
    }

    /**
     * Determine if a call or an assignment statement may start at the given token.
     */
    protected function startsStatement(Tokens $tokens, int $index): bool
    {
        if ($tokens[$index]->isGivenKind(T_STATIC)) {
            return $tokens[$tokens->getNextMeaningfulToken($index)]->isGivenKind(T_DOUBLE_COLON);
        }

        // A parenthesis starts a call on an expression, such as ($this->callback)($value).
        return $tokens[$index]->isGivenKind(self::STARTS) || $tokens[$index]->equals('(');
    }

    /**
     * Get the kind of the statement between the given tokens, if it is a call or an assignment.
     *
     * @return 'assignment'|'call'|null
     */
    protected function type(Tokens $tokens, ?int $start, ?int $end): ?string
    {
        if ($start === null || $end === null || ! $this->startsStatement($tokens, $start)) {
            return null;
        }

        $chain = true;

        for ($index = $start; $index < $end; $index = $tokens->getNextMeaningfulToken($index)) {
            if ($tokens[$index]->equalsAny(self::ASSIGNMENTS)) {
                return 'assignment';
            }

            if (($block = Tokens::detectBlockType($tokens[$index])) && $block['isStart']) {
                $index = $tokens->findBlockEnd($block['type'], $index);
            } elseif (! $tokens[$index]->isGivenKind(self::CHAIN)) {
                $chain = false;
            }
        }

        return $chain && $tokens[$tokens->getPrevMeaningfulToken($end)]->equals(')') ? 'call' : null;
    }

    /**
     * Add a blank line between the given statements unless they already have one.
     */
    protected function ensureBlankLine(Tokens $tokens, int $semicolon, int $next): void
    {
        $lineBreak = null;

        for ($index = $semicolon + 1; $index < $next; $index++) {
            if (! $tokens[$index]->isWhitespace() || ! str_contains($tokens[$index]->getContent(), "\n")) {
                continue;
            }

            if (substr_count($tokens[$index]->getContent(), "\n") > 1) {
                return;
            }

            $lineBreak ??= $index;
        }

        // Statements on the same line are left alone. Otherwise the blank line goes on the
        // first line break, so a comment above the second statement stays attached to it.
        if ($lineBreak !== null) {
            $tokens[$lineBreak] = new Token([T_WHITESPACE, $this->whitespaces->getLineEnding().$tokens[$lineBreak]->getContent()]);
        }
    }
}
