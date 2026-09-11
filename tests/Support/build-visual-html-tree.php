<?php

declare(strict_types=1);

/**
 * Generates representation of the semantic HTML tree structure.
 *
 * Inspired by the HTML5lib tree representation, extended for WordPress blocks.
 * Normalizes attribute order, class names, style whitespace, and block attributes.
 *
 * @see https://developer.wordpress.org/news/2026/02/a-better-way-to-test-html-in-wordpress-with-assertequalhtml/
 * @see https://github.com/WordPress/wordpress-develop/blob/trunk/tests/phpunit/includes/build-visual-html-tree.php
 *
 * @since WordPress 6.9.0
 *
 * @throws \WP_HTML_Unsupported_Exception|\Exception If the markup could not be parsed.
 *
 * @param string      $html             Given test HTML.
 * @param string|null $fragment_context Context element in which to parse HTML, such as BODY or SVG.
 *
 * @return string Tree structure of parsed HTML, if supported.
 */
function build_visual_html_tree(string $html, ?string $fragment_context): string
{
    $processor = $fragment_context ? WP_HTML_Processor::create_fragment($html, $fragment_context) : WP_HTML_Processor::create_full_parser($html);

    if ($processor === null) {
        throw new \Exception('Could not create a parser.');
    }

    $treeIndent = '  ';

    $output = '';
    $indentLevel = 0;
    $wasText = null;
    $textNode = '';

    $blockContext = [];

    while ($processor->next_token()) {
        if ($processor->get_last_error() !== null) {
            break;
        }

        $tokenName = $processor->get_token_name();
        $token_type = $processor->get_token_type();
        $isCloser = $processor->is_tag_closer();

        if ($wasText && $tokenName !== '#text') {
            if ($textNode !== '') {
                $output .= "{$textNode}\"\n";
            }
            $wasText = false;
            $textNode = '';
        }

        switch ($token_type) {
            case '#doctype':
                $doctype = $processor->get_doctype_info();
                $output .= "<!DOCTYPE {$doctype->name}";
                if ($doctype->public_identifier !== null || $doctype->system_identifier !== null) {
                    $output .= " \"{$doctype->public_identifier}\" \"{$doctype->system_identifier}\"";
                }
                $output .= ">\n";
                break;

            case '#tag':
                $namespace = $processor->get_namespace();
                $tag_name = $namespace === 'html' ? strtolower($processor->get_tag()) : "{$namespace} {$processor->get_qualified_tag_name()}";

                if ($isCloser) {
                    --$indentLevel;

                    if ($namespace === 'html' && $tokenName === 'TEMPLATE') {
                        --$indentLevel;
                    }

                    break;
                }

                $tagIndent = $indentLevel;

                if ($processor->expects_closer()) {
                    ++$indentLevel;
                }

                $output .= str_repeat($treeIndent, $tagIndent) . "<{$tag_name}>\n";

                $attribute_names = $processor->get_attribute_names_with_prefix('');
                if ($attribute_names) {
                    $sortedAttributes = [];
                    foreach ($attribute_names as $attribute_name) {
                        $sortedAttributes[$attribute_name] = $processor->get_qualified_attribute_name($attribute_name);
                    }

                    uasort(
                        $sortedAttributes,
                        static function ($a, $b): int {
                            $aHasNamespace = str_contains($a, ':');
                            $bHasNamespace = str_contains($b, ':');

                            if ($aHasNamespace !== $bHasNamespace) {
                                return $aHasNamespace ? 1 : -1;
                            }

                            $aHasSpace = str_contains($a, ' ');
                            $bHasSpace = str_contains($b, ' ');

                            if ($aHasSpace !== $bHasSpace) {
                                return $aHasSpace ? 1 : -1;
                            }

                            return $a <=> $b;
                        }
                    );

                    foreach ($sortedAttributes as $attribute_name => $displayName) {
                        $val = $processor->get_attribute($attribute_name);

                        if ($val === true) {
                            $val = '';
                        } elseif ($attribute_name === 'class') {
                            $classNames = iterator_to_array($processor->class_list());
                            sort($classNames, SORT_STRING);
                            $val = implode(' ', $classNames);
                        } elseif ($attribute_name === 'style') {
                            $normalizedStyle = '';
                            foreach (explode(';', $val) as $style) {
                                if (empty(trim($style))) {
                                    continue;
                                }
                                [$styleKey, $styleValue] = explode(':', $style);

                                $styleKey = trim($styleKey);
                                $styleValue = trim($styleValue);

                                $normalizedStyle .= "{$styleKey}:{$styleValue};";
                            }
                            $val = $normalizedStyle;
                        }
                        $output .= str_repeat($treeIndent, $tagIndent + 1) . "{$displayName}=\"{$val}\"\n";
                    }
                }

                $modifiable_text = $processor->get_modifiable_text();
                if ($modifiable_text !== '') {
                    $output .= str_repeat($treeIndent, $tagIndent + 1) . "\"{$modifiable_text}\"\n";
                }

                if ($namespace === 'html' && $tokenName === 'TEMPLATE') {
                    $output .= str_repeat($treeIndent, $indentLevel) . "content\n";
                    ++$indentLevel;
                }

                break;

            case '#cdata-section':
            case '#text':
                $textContent = $processor->get_modifiable_text();
                if ($textContent === '') {
                    break;
                }
                $wasText = true;
                if ($textNode === '') {
                    $textNode .= str_repeat($treeIndent, $indentLevel) . '"';
                }
                $textNode .= $textContent;
                break;

            case '#funky-comment':
                $output .= str_repeat($treeIndent, $indentLevel) . "<!-- {$processor->get_modifiable_text()} -->\n";
                break;

            case '#comment':
                $comment = "<!--{$processor->get_full_comment_text()}-->";

                $parser = new WP_Block_Parser();
                $parser->document = $comment;
                $parser->offset = 0;
                [$delimiterType, $blockName, $blockAttrs] = $parser->next_token();

                switch ($delimiterType) {
                    case 'block-opener':
                    case 'void-block':
                        $output .= str_repeat($treeIndent, $indentLevel) . "BLOCK[\"{$blockName}\"]\n";

                        if ($delimiterType === 'block-opener') {
                            $blockContext[] = $blockName;
                            ++$indentLevel;
                        }

                        if (empty($blockAttrs)) {
                            break;
                        }

                        ksort($blockAttrs, SORT_STRING);

                        if (isset($blockAttrs['className'])) {
                            $blockClassProcessor = new WP_HTML_Tag_Processor('<div>');
                            $blockClassProcessor->next_token();
                            $blockClassProcessor->set_attribute('class', $blockAttrs['className']);
                            $classNames = iterator_to_array($blockClassProcessor->class_list());
                            sort($classNames, SORT_STRING);
                            $blockAttrs['className'] = implode(' ', $classNames);
                        }

                        $blockAttrs = json_encode($blockAttrs, JSON_PRETTY_PRINT);
                        $blockAttrs = preg_replace('/^( +)\1/m', str_repeat($treeIndent, $indentLevel) . '$1', $blockAttrs);
                        $output .= str_repeat($treeIndent, $indentLevel) . substr($blockAttrs, 0, -1) . str_repeat($treeIndent, $indentLevel) . "}\n";
                        break;
                    case 'block-closer':
                        if ($blockContext !== [] && end($blockContext) === $blockName) {
                            --$indentLevel;
                            array_pop($blockContext);
                        }
                        break;
                    default:
                        $output .= str_repeat($treeIndent, $indentLevel) . $comment . "\n";
                        break;
                }
                break;
            default:
                $serializedTokenType = var_export($processor->get_token_type(), true); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
                throw new \Exception("Unhandled token type for tree construction: {$serializedTokenType}"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
    }

    if ($processor->get_unsupported_exception() !== null) {
        throw $processor->get_unsupported_exception();
    }

    if ($processor->get_last_error() !== null) {
        throw new \Exception("Parser error: {$processor->get_last_error()}"); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    if ($processor->paused_at_incomplete_token()) {
        throw new \Exception('Paused at incomplete token.');
    }

    if ($textNode !== '') {
        $output .= "{$textNode}\"\n";
    }

    return $output;
}
