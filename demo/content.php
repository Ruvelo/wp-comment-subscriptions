<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/*
 * The demo's content: Halyard, a made-up B2B billing platform, and the
 * conversation under its "Usage-based billing is here" post. Read by
 * src/Demo.php (`wp ruvelo-comment-subscriptions demo`).
 *
 * People with an account: Maya Okafor (runs product), Tom Reyes, Inès
 * Laurent, Kenji Mori. Everyone else comments as a guest.
 *
 * Comments: [key, author, hours ago, text, parent key|null, status]
 * Subscriptions: [post key, who, type, frequency, status, source, days ago]
 * STCR rows (Subscribe To Comments Reloaded's own format, to import):
 *   [post key|post id, email, days ago, status code]
 */

return [
    'posts' => [
        'usage' => [
            'title' => 'Usage-based billing is here',
            'days' => 2.2,
            'content' => <<<'HTML'
<!-- wp:paragraph -->
<p>Charge for what your customers actually use. <strong>Usage-based billing</strong> lets you attach a meter to any price: API calls, seats, gigabytes or anything else you count. Halyard totals the events, rates them and puts them on the next invoice.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">What’s new</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><li><strong>Meters</strong> on any price, fed through the API or a CSV upload.</li><li><strong>Tiers and free allowances</strong>: the first 10,000 calls free, then €0.40 per thousand.</li><li><strong>Projected usage</strong> on the invoice preview, so customers see the bill coming.</li></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>It’s live for every workspace today. Nothing changes for customers on flat plans. Tell us below how it goes: we read every comment, and we’ll answer here.</p>
<!-- /wp:paragraph -->
HTML,
        ],
        'pricing' => [
            'title' => 'How we price API calls',
            'days' => 34,
            'content' => <<<'HTML'
<!-- wp:paragraph -->
<p>A look behind our own pricing page: why we count API calls the way we do, and what we learned from the first hundred teams on metered plans.</p>
<!-- /wp:paragraph -->
HTML,
        ],
        'editor' => [
            'title' => 'Halyard 2.0: a faster invoice editor',
            'days' => 210,
            'content' => <<<'HTML'
<!-- wp:paragraph -->
<p>The invoice editor, rebuilt: line items you can drag, taxes that update as you type, and a preview that matches the PDF to the pixel.</p>
<!-- /wp:paragraph -->
HTML,
        ],
        'close' => [
            'title' => 'Closing the books in three days',
            'days' => 380,
            'content' => <<<'HTML'
<!-- wp:paragraph -->
<p>How three finance teams cut their month-end close from two weeks to three days, with Halyard doing the reconciliation.</p>
<!-- /wp:paragraph -->
HTML,
        ],
    ],

    'comments' => [
        ['tom', 'Tom Reyes', 48, 'This is the one we’ve been waiting for. Does metering work with our existing Stripe price IDs, or do we need to recreate every product?', null, 1],
        ['maya1', 'Maya Okafor', 47, "Existing price IDs work. Halyard maps each meter to a price, so you only add a meter key to the price you already have.\n\nNothing changes for customers on flat plans.", 'tom', 1],
        ['tom2', 'Tom Reyes', 45, 'Perfect, thanks Maya. Migrating our first plan this afternoon.', 'maya1', 1],
        ['ines', 'Inès Laurent', 24, "We moved two customers over this morning. The invoice preview showing projected usage is a lovely touch.\n\nOne request: could the CSV export include the meter name? Finance asked within the hour.", null, 1],
        ['kenji1', 'Kenji Mori', 22, 'Seconding this. Also, is there a way to backfill usage from before the switch?', 'ines', 1],
        ['sam', 'Sam Patel', 20, 'Do events get rounded per event or per invoice line? Our totals are off by a cent.', null, 1],
        ['kenji2', 'Kenji Mori', 19, 'Per invoice line, so fractions only round once. The rounding notes in the docs have a worked example.', 'sam', 1],
        ['kenji3', 'Kenji Mori', 3, 'Heads-up for anyone on the API: usage timestamps are in UTC. Our dashboard was a day off until I noticed.', null, 1],
        ['maya2', 'Maya Okafor', 1, 'Thanks, everyone. Meter names in the CSV export ship next week. Backfill is in beta: reply here and I’ll switch it on for your account.', null, 1],
        ['lena', 'Lena Fischer', 0.6, 'Yes please, backfill for us too! We have three months of usage in a spreadsheet.', 'maya2', 1],
        ['ravi', 'Ravi Shah', 0.4, 'Is there an annual discount for usage-based plans, or is it monthly only?', null, 0],
        ['spam', 'Ana Costa', 0.2, 'Cheap API credits!!! 90% off at cheap-credits.example, limited time', null, 'spam'],
        ['p1', 'Jonas Berg', 30 * 24, 'Counting per thousand calls instead of per call made our invoices so much easier to read. Good call.', null, 1, 'pricing'],
        ['p2', 'Maya Okafor', 30 * 24 - 5, 'Thanks Jonas! Per-call pricing looked fairer on paper, but nobody could check their invoice.', 'p1', 1, 'pricing'],
        ['e1', 'Kenji Mori', 200 * 24, 'Drag-and-drop line items, finally. The tax preview is great too.', null, 1, 'editor'],
    ],

    'subscriptions' => [
        ['usage', 'Tom Reyes', 'replies', 'instant', 'active', 'comment', 2],
        ['usage', 'Inès Laurent', 'all', 'daily', 'active', 'comment', 1],
        ['usage', 'Kenji Mori', 'all', 'instant', 'active', 'comment', 0.9],
        ['usage', 'sam@patel.example', 'all', 'instant', 'active', 'comment', 0.8],
        ['usage', 'lena.fischer@example.org', 'replies', 'instant', 'active', 'comment', 0.03],
        ['usage', 'jonas@berg.example', 'all', 'daily', 'active', 'form', 1.5],
        ['usage', 'priya@raman.example', 'all', 'instant', 'active', 'form', 1.2],
        ['usage', 'daniel.kim@example.com', 'all', 'instant', 'active', 'form', 0.7],
        ['usage', 'hannah@brooks.example', 'replies', 'instant', 'active', 'comment', 0.5],
        ['usage', 'ravi.shah@example.net', 'all', 'instant', 'pending', 'comment', 0.02],
        ['usage', 'amara@nwosu.example', 'all', 'instant', 'pending', 'form', 0.3],
        ['pricing', 'jonas@berg.example', 'all', 'daily', 'active', 'comment', 30],
        ['pricing', 'Kenji Mori', 'all', 'instant', 'active', 'form', 29],
        ['pricing', 'lucas@ferreira.example', 'all', 'instant', 'active', 'form', 25],
        ['editor', 'Kenji Mori', 'replies', 'instant', 'active', 'comment', 200],
    ],

    // Left behind by Subscribe To Comments Reloaded, for the importer.
    'stcr' => [
        ['editor', 'hannah@brooks.example', 190, 'Y'],
        ['editor', 'lucas@ferreira.example', 186, 'Y'],
        ['editor', 'amara@nwosu.example', 180, 'R'],
        ['editor', 'old.customer@example.com', 172, 'YC'],
        ['editor', 'mei.chen@example.com', 150, 'Y'],
        ['editor', 'kenji.mori@halyard.test', 200, 'R'],
        ['close', 'hannah@brooks.example', 370, 'Y'],
        ['close', 'olivia@martins.example', 365, 'R'],
        ['close', 'noah@novak.example', 360, 'Y'],
        ['close', 'suspended@example.com', 355, 'RC'],
        ['close', 'not-an-address', 350, 'Y'],
        ['close', 'priya@raman.example', 340, 'D'],
        ['close', 'theo@larsen.example', 330, 'Y'],
        ['close', 'rosa@moreau.example', 320, 'R'],
        [999999, 'gone@example.com', 400, 'Y'],
    ],
];
