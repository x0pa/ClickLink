<?php

declare(strict_types=1);

function clicklink_fixture_paragraph_scope_content(): string
{
    return <<<HTML
<h2>Apple heading</h2>
<div>apple in div block.</div>
<p>Apple and banana in first paragraph.</p>
<p>Existing <a href="https://external.example.com">apple</a> link, <code>banana</code> code, and apple text.</p>
<pre>apple in pre block</pre>
<p>banana banana banana banana banana banana</p>
HTML;
}

function clicklink_fixture_keyword_boundary_content(): string
{
    return '<p>art start artful cart Art.</p>';
}

function clicklink_fixture_dense_keyword_content(): string
{
    return '<p>apple apple apple apple apple apple apple</p>';
}

function clicklink_fixture_exclusion_and_encoding_content(): string
{
    return <<<HTML
<p data-note="alpha > beta">alpha &amp; beta in paragraph text.</p>
<p><script>var sample = "<p>alpha</p>";</script>alpha <style>.alpha{display:block;}</style> alpha</p>
<h3>alpha heading</h3>
<p>alpha in <textarea>alpha hidden</textarea> visible alpha.</p>
HTML;
}

function clicklink_fixture_nested_html_content(): string
{
    return <<<HTML
<h2>alpha heading</h2>
<p>alpha <span>beta <strong>alpha</strong></span> and <a href="https://external.example.com/alpha">alpha</a>.</p>
<p><code>alpha</code> plus <em>alpha</em> within nested inline tags.</p>
HTML;
}

function clicklink_fixture_heading_heavy_content(): string
{
    return <<<HTML
<h1>alpha title</h1>
<h2>alpha subtitle</h2>
<section><h3>alpha section</h3><div>alpha in non-paragraph content.</div></section>
HTML;
}
