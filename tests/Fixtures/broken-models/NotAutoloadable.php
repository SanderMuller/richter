<?php declare(strict_types=1);

// Deliberately not autoloadable: the checker maps this file to App\Models\NotAutoloadable, whose
// class_exists() lookup fails — marking the model set incomplete (the degraded path under test).
