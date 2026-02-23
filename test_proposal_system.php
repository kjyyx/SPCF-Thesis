<?php
echo "=== Testing Project Proposal System ===\n";

// Mock form data that matches the new structure
$mockData = [
    'date' => '2026-02-25',
    'organizer' => 'John Doe',
    'department' => 'College of Engineering',
    'departmentFull' => 'College of Engineering',
    'title' => 'Test Project',
    'lead' => 'Jane Smith',
    'rationale' => 'Test rationale for the project',
    'objectives' => ['Objective 1', 'Objective 2'],
    'ilos' => ['ILO 1', 'ILO 2'],
    'budgetSource' => 'SSC Funds',
    'venue' => 'Engineering Building',
    'mechanics' => 'Test mechanics description',
    'schedule' => [
        ['date' => '2026-02-25', 'time' => '10:00'],
        ['date' => '2026-02-26', 'time' => '14:00']
    ],
    'program' => [
        ['start' => '09:00', 'end' => '10:00', 'act' => 'Registration'],
        ['start' => '10:00', 'end' => '11:00', 'act' => 'Opening Ceremony']
    ],
    'budget' => [
        ['name' => 'Materials', 'price' => 1000, 'size' => 'N/A', 'qty' => 1, 'total' => 1000]
    ]
];

echo "✓ Mock data structure created\n";

// Test template filling logic (same as in documents.php)
function testTemplateFilling($data) {
    $results = [];

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if ($key === 'objectives' || $key === 'ilos') {
                // Simple bulleted list for arrays of strings
                $value = implode("\n• ", array_map('htmlspecialchars', $value));
            } elseif ($key === 'program') {
                // Format program schedule
                $lines = [];
                foreach ($value as $item) {
                    $lines[] = htmlspecialchars("{$item['start']} - {$item['end']}: {$item['act']}");
                }
                $value = implode("\n", $lines);
            } elseif ($key === 'schedule') {
                // Format schedule summaries as date/time list
                $lines = [];
                foreach ($value as $item) {
                    if (!empty($item['date']) && !empty($item['time'])) {
                        $lines[] = htmlspecialchars("{$item['date']} at {$item['time']}");
                    } elseif (!empty($item['date'])) {
                        $lines[] = htmlspecialchars($item['date']);
                    } elseif (!empty($item['time'])) {
                        $lines[] = htmlspecialchars($item['time']);
                    }
                }
                $value = implode("\n", $lines);
            } elseif ($key === 'budget') {
                // Format budget table as text
                $lines = [];
                foreach ($value as $item) {
                    $lines[] = htmlspecialchars("{$item['name']} - {$item['size']} - Qty: {$item['qty']} - Price: ₱{$item['price']} - Total: ₱{$item['total']}");
                }
                $value = implode("\n", $lines);
            } else {
                $value = implode(", ", array_map('htmlspecialchars', $value));
            }
        } elseif (is_string($value)) {
            $value = htmlspecialchars($value);
        }

        $results[$key] = $value;
    }

    return $results;
}

$filledData = testTemplateFilling($mockData);
echo "✓ Template filling logic executed\n";

// Test key outputs
echo "\n=== Key Template Outputs ===\n";
echo "schedule: {$filledData['schedule']}\n";
echo "program: {$filledData['program']}\n";
echo "objectives: {$filledData['objectives']}\n";
echo "ilos: {$filledData['ilos']}\n";
echo "budget: {$filledData['budget']}\n";

// Test JSON encoding/decoding (for database storage)
$jsonSchedule = json_encode($mockData['schedule']);
$decodedSchedule = json_decode($jsonSchedule, true);

echo "\n=== JSON Storage Test ===\n";
echo "✓ JSON encoding: " . ($jsonSchedule ? 'SUCCESS' : 'FAILED') . "\n";
echo "✓ JSON decoding: " . (is_array($decodedSchedule) && count($decodedSchedule) === 2 ? 'SUCCESS' : 'FAILED') . "\n";
echo "✓ Schedule entries preserved: " . (count($decodedSchedule) === 2 ? 'YES' : 'NO') . "\n";

// Test preview HTML generation (simplified version)
function generateSimplePreview($d) {
    $objectivesHtml = (isset($d['objectives']) && is_array($d['objectives']) && count($d['objectives'])) ?
        '<ul style="margin:0;padding-left:20px">' . implode('', array_map(function($o) {
            return '<li>' . htmlspecialchars($o) . '</li>';
        }, $d['objectives'])) . '</ul>' :
        '<div class="text-muted">No objectives provided</div>';

    $scheduleHtml = (isset($d['schedule']) && is_array($d['schedule']) && count($d['schedule'])) ?
        '<ul style="margin:0;padding-left:20px">' . implode('', array_map(function($s) {
            return '<li>' . htmlspecialchars($s['date'] . ($s['time'] ? ' at ' . $s['time'] : '')) . '</li>';
        }, $d['schedule'])) . '</ul>' :
        '<em>No schedule provided</em>';

    return "<div><strong>Project Title:</strong> " . htmlspecialchars($d['title'] ?? '') . "</div>" .
           "<div><strong>Schedule:</strong><div>{$scheduleHtml}</div></div>" .
           "<div><strong>Objectives:</strong>{$objectivesHtml}</div>";
}

$previewHtml = generateSimplePreview($mockData);
echo "\n=== Preview HTML Test ===\n";
echo "✓ Preview HTML generated: " . (strpos($previewHtml, 'Test Project') !== false ? 'SUCCESS' : 'FAILED') . "\n";
echo "✓ Schedule in preview: " . (strpos($previewHtml, '2026-02-25 at 10:00') !== false ? 'SUCCESS' : 'FAILED') . "\n";
echo "✓ Objectives in preview: " . (strpos($previewHtml, 'Objective 1') !== false ? 'SUCCESS' : 'FAILED') . "\n";

echo "\n=== ALL TESTS COMPLETED ===\n";
echo "If all tests show SUCCESS, the system is ready for testing!\n";
?>