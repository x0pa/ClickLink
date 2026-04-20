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
