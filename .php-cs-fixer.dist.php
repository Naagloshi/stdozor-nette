<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
	->in(__DIR__ . '/app')
	->in(__DIR__ . '/tests')
	->name('*.php')
	->name('*.phpt');

return (new PhpCsFixer\Config())
	->setRiskyAllowed(false)
	->setIndent("\t")
	->setLineEnding("\n")
	->setRules([
		// PSR-12 base (non-risky only)
		'@PER-CS2.0' => true,

		// Spacing & formatting
		'array_indentation' => true,
		'array_syntax' => ['syntax' => 'short'],
		'binary_operator_spaces' => ['default' => 'single_space'],
		'blank_line_after_opening_tag' => true,
		'blank_line_before_statement' => ['statements' => ['return', 'throw', 'try']],
		'cast_spaces' => ['space' => 'single'],
		'class_attributes_separation' => ['elements' => ['method' => 'one', 'property' => 'one']],
		'concat_space' => ['spacing' => 'one'],
		'function_typehint_space' => true,
		'include' => true,
		'lowercase_cast' => true,
		'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
		'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
		'no_blank_lines_after_phpdoc' => true,
		'no_empty_comment' => true,
		'no_empty_phpdoc' => true,
		'no_empty_statement' => true,
		'no_extra_blank_lines' => ['tokens' => ['extra', 'use']],
		'no_leading_import_slash' => true,
		'no_leading_namespace_whitespace' => true,
		'no_mixed_echo_print' => ['use' => 'echo'],
		'no_multiline_whitespace_around_double_arrow' => true,
		'no_short_bool_cast' => true,
		'no_singleline_whitespace_before_semicolons' => true,
		'no_spaces_around_offset' => true,
		'no_trailing_comma_in_singleline' => true,
		'no_unneeded_control_parentheses' => true,
		'no_unused_imports' => true,
		'no_whitespace_before_comma_in_array' => true,
		'no_whitespace_in_blank_line' => true,
		'normalize_index_brace' => true,
		'object_operator_without_whitespace' => true,
		'ordered_imports' => ['sort_algorithm' => 'alpha'],
		'phpdoc_align' => ['align' => 'left'],
		'phpdoc_indent' => true,
		'phpdoc_no_useless_inheritdoc' => true,
		'phpdoc_scalar' => true,
		'phpdoc_separation' => true,
		'phpdoc_single_line_var_spacing' => true,
		'phpdoc_trim' => true,
		'phpdoc_types' => true,
		'return_type_declaration' => ['space_before' => 'none'],
		'semicolon_after_instruction' => true,
		'short_scalar_cast' => true,
		'blank_lines_before_namespace' => ['min_line_breaks' => 2, 'max_line_breaks' => 2],
		'single_line_comment_style' => ['comment_types' => ['hash']],
		'single_quote' => true,
		'space_after_semicolon' => true,
		'standardize_not_equals' => true,
		'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
		'trim_array_spaces' => true,
		'type_declaration_spaces' => true,
		'unary_operator_spaces' => true,
		'whitespace_after_comma_in_array' => true,
	])
	->setFinder($finder);
