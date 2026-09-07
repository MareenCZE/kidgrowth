<?php

/**
 * JSON-file storage backend - the zero-setup default.
 *
 * The whole dataset lives in one file as {"children": [...], "measurements":
 * [...]}. Every write reads the current file under an exclusive lock, applies
 * the change, and writes the result to a temp file in the same directory
 * before rename()-ing it into place: rename() is atomic within a filesystem,
 * so a reader never observes a half-written file, and the lock means two
 * writers never interleave.
 *
 * This is a deliberate trade, not an oversight: write volume for a use case
 * like this is a handful of measurements a month from two people who are
 * not typing simultaneously, and the whole dataset is a few kilobytes, so
 * rewriting it wholesale on every write costs nothing.
 */

function growth_json_path()
{
    global $JSON_STORAGE_PATH;
    return $JSON_STORAGE_PATH;
}

/** Reads the store, ignoring locks - used only where a write follows under
 *  its own lock in the same request (see growth_json_write()). */
function growth_json_read()
{
    $path = growth_json_path();
    if (!is_file($path)) {
        return array('children' => array(), 'measurements' => array());
    }
    $handle = @fopen($path, 'r');
    if (!$handle) {
        return array('children' => array(), 'measurements' => array());
    }
    flock($handle, LOCK_SH);
    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return growth_json_decode($contents);
}

function growth_json_decode($contents)
{
    $data = json_decode((string)$contents, true);
    if (!is_array($data)) {
        $data = array();
    }
    return $data + array('children' => array(), 'measurements' => array());
}

/**
 * Reads the store under an exclusive lock, lets $mutator transform it, and
 * writes the result back atomically. The read happens while the lock is
 * held, so two requests can never interleave a lost update.
 */
function growth_json_write(callable $mutator)
{
    $path = growth_json_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $handle = fopen($path, 'c+');
    if (!$handle) {
        return false;
    }
    flock($handle, LOCK_EX);

    $data = growth_json_decode(stream_get_contents($handle));
    $data = $mutator($data);

    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    file_put_contents($tmp, json_encode($data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);

    flock($handle, LOCK_UN);
    fclose($handle);
    return true;
}

function growth_json_next_id($rows)
{
    $max = 0;
    foreach ($rows as $row) {
        $max = max($max, (int)$row['id']);
    }
    return $max + 1;
}

/* ---------------------------------------------------------------- children */

function growth_storage_children()
{
    $rows = array_values(array_filter(growth_json_read()['children'], function ($c) {
        return empty($c['deleted_at']);
    }));
    /* Oldest first, and the id breaks a tie so that twins keep a stable
       order rather than one that depends on the sort implementation. The
       three backends agree on this ordering; see the SQL ones. */
    usort($rows, function ($a, $b) {
        return [$a['birth_date'], (int)$a['id']] <=> [$b['birth_date'], (int)$b['id']];
    });
    return $rows;
}

function growth_storage_children_deleted()
{
    $rows = array_values(array_filter(growth_json_read()['children'], function ($c) {
        return !empty($c['deleted_at']);
    }));
    usort($rows, function ($a, $b) {
        return strcmp($b['deleted_at'], $a['deleted_at']);
    });
    return $rows;
}

function growth_storage_child($id)
{
    foreach (growth_json_read()['children'] as $c) {
        if ((int)$c['id'] === $id && empty($c['deleted_at'])) {
            return $c;
        }
    }
    return null;
}

function growth_storage_child_deleted_at($id)
{
    foreach (growth_json_read()['children'] as $c) {
        if ((int)$c['id'] === $id) {
            return $c['deleted_at'] ?: null;
        }
    }
    return null;
}

function growth_storage_child_upsert($name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $id = null;
    growth_json_write(function ($data) use ($name, $sex, $born, $fatherCm, $motherCm, $breastfed, &$id) {
        foreach ($data['children'] as $c) {
            if ($c['name'] === $name && empty($c['deleted_at'])) {
                $id = (int)$c['id'];
                return $data;
            }
        }
        $id = growth_json_next_id($data['children']);
        $data['children'][] = array(
            'id' => $id, 'name' => $name, 'sex' => $sex, 'birth_date' => $born,
            'father_height_cm' => $fatherCm, 'mother_height_cm' => $motherCm,
            'breastfed' => $breastfed, 'deleted_at' => null,
        );
        return $data;
    });
    return $id;
}

function growth_storage_child_update($id, $name, $sex, $born, $fatherCm, $motherCm, $breastfed)
{
    $ok = false;
    growth_json_write(function ($data) use ($id, $name, $sex, $born, $fatherCm, $motherCm, $breastfed, &$ok) {
        foreach ($data['children'] as &$c) {
            if ((int)$c['id'] === $id) {
                $c['name'] = $name;
                $c['sex'] = $sex;
                $c['birth_date'] = $born;
                $c['father_height_cm'] = $fatherCm;
                $c['mother_height_cm'] = $motherCm;
                $c['breastfed'] = $breastfed;
                $ok = true;
            }
        }
        unset($c);
        return $data;
    });
    return $ok;
}

function growth_storage_child_delete($id, $when)
{
    $ok = false;
    growth_json_write(function ($data) use ($id, $when, &$ok) {
        foreach ($data['children'] as &$c) {
            if ((int)$c['id'] === $id && empty($c['deleted_at'])) {
                $c['deleted_at'] = $when;
                $ok = true;
            }
        }
        unset($c);
        return $data;
    });
    return $ok;
}

function growth_storage_child_restore($id)
{
    $ok = false;
    growth_json_write(function ($data) use ($id, &$ok) {
        foreach ($data['children'] as &$c) {
            if ((int)$c['id'] === $id && !empty($c['deleted_at'])) {
                $c['deleted_at'] = null;
                $ok = true;
            }
        }
        unset($c);
        return $data;
    });
    return $ok;
}

/* ------------------------------------------------------------ measurements */

function growth_storage_measurements($childId)
{
    $rows = array_values(array_filter(growth_json_read()['measurements'], function ($m) use ($childId) {
        return (int)$m['child_id'] === $childId && empty($m['deleted_at']);
    }));
    usort($rows, function ($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    return $rows;
}

function growth_storage_measurements_deleted($childId)
{
    $rows = array_values(array_filter(growth_json_read()['measurements'], function ($m) use ($childId) {
        return (int)$m['child_id'] === $childId && !empty($m['deleted_at']);
    }));
    usort($rows, function ($a, $b) {
        return strcmp($b['deleted_at'], $a['deleted_at']);
    });
    return $rows;
}

function growth_storage_measurement_save($childId, $date, $heightCm, $weightKg, $note)
{
    growth_json_write(function ($data) use ($childId, $date, $heightCm, $weightKg, $note) {
        foreach ($data['measurements'] as &$m) {
            if ((int)$m['child_id'] === $childId && $m['date'] === $date) {
                $m['height_cm'] = $heightCm;
                $m['weight_kg'] = $weightKg;
                $m['note'] = $note;
                $m['deleted_at'] = null;
                return $data;
            }
        }
        unset($m);
        $id = growth_json_next_id($data['measurements']);
        $data['measurements'][] = array(
            'id' => $id, 'child_id' => $childId, 'date' => $date,
            'height_cm' => $heightCm, 'weight_kg' => $weightKg, 'note' => $note, 'deleted_at' => null,
        );
        return $data;
    });
    return true;
}

/**
 * Rewrites one measurement, identified by its id rather than its date.
 *
 * growth_storage_measurement_save() is keyed on (child, date), which makes a
 * wrong date impossible to correct: saving with the right one leaves the
 * original row behind. This is what "edit this row" needs instead.
 *
 * Returns false rather than merging when the new date already belongs to
 * another row of the same child - deleted or not, matching the unique index
 * the SQL backends carry. Silently collapsing two measurements into one is
 * never what someone correcting a typo meant.
 */
function growth_storage_measurement_update($childId, $id, $date, $heightCm, $weightKg, $note)
{
    $ok = false;
    growth_json_write(function ($data) use ($childId, $id, $date, $heightCm, $weightKg, $note, &$ok) {
        foreach ($data['measurements'] as $other) {
            if ((int)$other['id'] !== $id
                && (int)$other['child_id'] === $childId
                && $other['date'] === $date) {
                return $data;
            }
        }
        foreach ($data['measurements'] as &$row) {
            if ((int)$row['id'] === $id && (int)$row['child_id'] === $childId && empty($row['deleted_at'])) {
                $row['date'] = $date;
                $row['height_cm'] = $heightCm;
                $row['weight_kg'] = $weightKg;
                $row['note'] = $note;
                $ok = true;
                break;
            }
        }
        unset($row);
        return $data;
    });
    return $ok;
}

function growth_storage_measurement_delete($childId, $id, $when)
{
    $ok = false;
    growth_json_write(function ($data) use ($childId, $id, $when, &$ok) {
        foreach ($data['measurements'] as &$m) {
            if ((int)$m['id'] === $id && (int)$m['child_id'] === $childId && empty($m['deleted_at'])) {
                $m['deleted_at'] = $when;
                $ok = true;
            }
        }
        unset($m);
        return $data;
    });
    return $ok;
}

function growth_storage_measurement_restore($childId, $id)
{
    $ok = false;
    growth_json_write(function ($data) use ($childId, $id, &$ok) {
        foreach ($data['measurements'] as &$m) {
            if ((int)$m['id'] === $id && (int)$m['child_id'] === $childId && !empty($m['deleted_at'])) {
                $m['deleted_at'] = null;
                $ok = true;
            }
        }
        unset($m);
        return $data;
    });
    return $ok;
}

function growth_storage_measurements_delete_for_child($childId, $when)
{
    growth_json_write(function ($data) use ($childId, $when) {
        foreach ($data['measurements'] as &$m) {
            if ((int)$m['child_id'] === $childId && empty($m['deleted_at'])) {
                $m['deleted_at'] = $when;
            }
        }
        unset($m);
        return $data;
    });
    return true;
}

function growth_storage_measurements_restore_for_child($childId, $when)
{
    growth_json_write(function ($data) use ($childId, $when) {
        foreach ($data['measurements'] as &$m) {
            if ((int)$m['child_id'] === $childId && isset($m['deleted_at']) && $m['deleted_at'] === $when) {
                $m['deleted_at'] = null;
            }
        }
        unset($m);
        return $data;
    });
    return true;
}
