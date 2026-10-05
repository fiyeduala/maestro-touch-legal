<?php

return [
    // Public forms sent sooner than this after the page loaded are treated as automated and dropped (D48).
    // 0 turns the check off (the test suite does; tests/Feature/Site/FormSpamTest.php turns it back on).
    'min_seconds' => (int) env('FORMS_MIN_SECONDS', 3),
];
