<?php

// RackTables Quick Photo - plugin (new plugin architecture, RackTables 0.22+).
//
// Adds a "Photos" tab to locations, rows, racks and objects. The tab lists the
// linked image files and offers phone-friendly Add / Retake controls (the
// upload ops and the JS client are separate parts of the plugin).
// No database tables, no config variables.

require_once __DIR__ . '/lib.php';

function plugin_quickphoto_info ()
{
	return array
	(
		'name' => 'quickphoto',
		'longname' => 'Quick Photo',
		'version' => '1.0.0',
		'home_url' => '',
	);
}

function plugin_quickphoto_realms ()
{
	return array ('location', 'row', 'rack', 'object');
}

function plugin_quickphoto_init ()
{
	global $tab;
	foreach (plugin_quickphoto_realms() as $realm)
	{
		$tab[$realm]['photos'] = 'Photos';
		registerTabHandler ($realm, 'photos', 'plugin_quickphoto_render_tab');
		registerOpHandler ($realm, 'photos', 'addPhoto', 'plugin_quickphoto_op_add');
		registerOpHandler ($realm, 'photos', 'replacePhoto', 'plugin_quickphoto_op_replace');
	}
}

function plugin_quickphoto_install ()
{
	return TRUE;
}

function plugin_quickphoto_uninstall ()
{
	return TRUE;
}

function plugin_quickphoto_upgrade ()
{
	return TRUE;
}

// File types the plugin lists and may replace (the ones core can preview).
function plugin_quickphoto_image_types ()
{
	return array ('image/jpeg', 'image/png', 'image/gif');
}

// Plugin-owned CSRF token, kept in the PHP session. Created on first use.
function plugin_quickphoto_csrf_token ()
{
	startSession();
	if (! isset ($_SESSION['quickphoto_csrf']) || ! is_string ($_SESSION['quickphoto_csrf']) || $_SESSION['quickphoto_csrf'] === '')
		$_SESSION['quickphoto_csrf'] = bin2hex (random_bytes (32));
	$token = $_SESSION['quickphoto_csrf'];
	session_commit();
	return $token;
}

// Same permission as core "Upload replacement", evaluated in the file's context
// with the core operation name.
function plugin_quickphoto_can_replace ($file_cell)
{
	global $op;
	$saved = $op;
	$op = 'replaceFile';
	try
	{
		return isolatedPermission ('file', 'replaceData', $file_cell);
	}
	finally
	{
		$op = $saved;
	}
}

// Image files linked to the entity that the user may download, newest first.
// Each item is the file cell as returned by spotEntity ('file', id).
function plugin_quickphoto_photos ($realm, $entity_id)
{
	$ret = array();
	foreach (getFilesOfEntity ($realm, $entity_id) as $file_id => $row)
	{
		if (! in_array ($row['type'], plugin_quickphoto_image_types(), TRUE))
			continue;
		$cell = spotEntity ('file', $file_id);
		if (! isolatedPermission ('file', 'download', $cell))
			continue;
		$ret[] = $cell;
	}
	usort ($ret, function ($a, $b) { return strcmp ((string) $b['mtime'], (string) $a['mtime']); });
	return $ret;
}

// Other entities the file is linked to: names of those the user may view,
// and the count of the rest (including entity types this plugin does not know).
function plugin_quickphoto_link_warning ($file_id, $realm, $entity_id)
{
	$names = array();
	$hidden = 0;
	foreach (getFileLinks ($file_id) as $link)
	{
		if ($link['entity_type'] === $realm && (int) $link['entity_id'] === (int) $entity_id)
			continue;
		if (! in_array ($link['entity_type'], plugin_quickphoto_realms(), TRUE))
		{
			$hidden++;
			continue;
		}
		try
		{
			$cell = spotEntity ($link['entity_type'], $link['entity_id']);
			if (! isolatedPermission ($link['entity_type'], 'default', $cell))
			{
				$hidden++;
				continue;
			}
			$name = isset ($cell['dname']) ? $cell['dname'] : $cell['name'];
			$names[] = $name !== '' ? $name : $link['entity_type'] . ' ' . $link['entity_id'];
		}
		catch (EntityNotFoundException $e)
		{
			$hidden++;
		}
	}
	return array ('names' => $names, 'hidden' => $hidden);
}

function plugin_quickphoto_warning_text ($warning)
{
	if (! count ($warning['names']) && $warning['hidden'] < 1)
		return '';
	$text = 'This photo is also used in: ' . implode (', ', $warning['names']);
	if ($warning['hidden'] > 0)
	{
		$more = $warning['hidden'] . ' other place' . ($warning['hidden'] > 1 ? 's' : '');
		$text = count ($warning['names']) ? $text . ' and ' . $more : 'This photo is also used in ' . $more;
	}
	return $text . '. Replacing it changes it everywhere.';
}

function plugin_quickphoto_render_tab ($entity_id)
{
	global $pageno, $tabno;
	$realm = $pageno;
	$entity_id = (int) $entity_id;

	addPageHeader ('<meta name="viewport" content="width=device-width, initial-scale=1">', 'default');
	addCSSInternal ('quickphoto/css/quickphoto.css');
	addJSInternal ('quickphoto/js/quickphoto.js');

	$token = plugin_quickphoto_csrf_token();
	$photos = plugin_quickphoto_photos ($realm, $entity_id);
	$tok = qp_esc ($token);
	$entity = spotEntity ($realm, $entity_id);
	$label = qp_name_label (isset ($entity['name']) ? (string) $entity['name'] : '', $realm, $entity_id);
	$suggestion = qp_name_suggestion ($label, time());

	echo '<div id="quickphoto">';
	echo '<noscript><p class="qp-noscript">Quick Photo needs JavaScript. Use the Files tab to upload without it.</p></noscript>';

	echo '<form class="qp-add" method="post" enctype="multipart/form-data" action="' .
		qp_esc (makeHrefProcess (array ('op' => 'addPhoto'))) . '" data-name-suggestion="' . qp_esc ($suggestion) . '">';
	echo '<input type="hidden" name="qp_token" value="' . $tok . '">';
	echo '<label class="qp-pick qp-button">Add photo<input type="file" accept="image/*" class="qp-file"></label>';
	echo '<input type="text" name="comment" class="qp-comment" placeholder="Comment (optional)" maxlength="1000">';
	echo '</form>';

	if (! count ($photos))
		echo '<p class="qp-empty">No photos yet.</p>';
	echo '<div class="qp-grid">';
	foreach ($photos as $file)
	{
		$id = (int) $file['id'];
		$v = (string) strtotime ((string) $file['mtime']);
		$thumb = makeHref (array ('module' => 'image', 'img' => 'preview', 'file_id' => $id, 'v' => $v));
		$full = makeHref (array ('module' => 'download', 'file_id' => $id, 'asattach' => 'no', 'v' => $v));
		echo '<div class="qp-photo">';
		echo '<a href="' . qp_esc ($full) . '"><img class="qp-thumb" loading="lazy" alt="' .
			qp_esc ($file['name']) . '" src="' . qp_esc ($thumb) . '"></a>';
		echo '<div class="qp-name">' . qp_esc ($file['name']) . '</div>';
		if ($file['comment'] !== '' && $file['comment'] !== NULL)
			echo '<div class="qp-note">' . qp_esc ($file['comment']) . '</div>';
		if (plugin_quickphoto_can_replace ($file))
		{
			$links = array_values (getFileLinks ($id));
			$warning = plugin_quickphoto_warning_text (plugin_quickphoto_link_warning ($id, $realm, $entity_id));
			echo '<form class="qp-replace" method="post" enctype="multipart/form-data" action="' .
				qp_esc (makeHrefProcess (array ('op' => 'replacePhoto'))) . '" data-warning="' . qp_esc ($warning) . '">';
			echo '<input type="hidden" name="qp_token" value="' . $tok . '">';
			echo '<input type="hidden" name="file_id" value="' . $id . '">';
			echo '<input type="hidden" name="expected_mtime" value="' . qp_esc ((string) $file['mtime']) . '">';
			echo '<input type="hidden" name="expected_size" value="' . qp_esc ((string) $file['size']) . '">';
			echo '<input type="hidden" name="expected_links" value="' . qp_esc (qp_link_fingerprint ($links)) . '">';
			echo '<label class="qp-pick qp-button">Retake<input type="file" accept="image/*" class="qp-file"></label>';
			echo '</form>';
		}
		echo '</div>';
	}
	echo '</div>';
	echo '</div>';
}

// ---------------------------------------------------------------- ops

// Shared request gate for the upload ops: CSRF token plus Sec-Fetch-Site and
// Origin checks. Returns an error text, or NULL if the request may proceed.
function plugin_quickphoto_check_request ()
{
	if (isset ($_SERVER['HTTP_SEC_FETCH_SITE']) && strtolower ((string) $_SERVER['HTTP_SEC_FETCH_SITE']) === 'cross-site')
		return 'Cross-site request rejected.';
	if (isset ($_SERVER['HTTP_ORIGIN']))
	{
		$parts = parse_url ((string) $_SERVER['HTTP_ORIGIN']);
		$host = is_array ($parts) && isset ($parts['host']) ? strtolower ($parts['host']) : '';
		if ($host !== '' && isset ($parts['port']))
			$host .= ':' . $parts['port'];
		$mine = isset ($_SERVER['HTTP_HOST']) ? strtolower ((string) $_SERVER['HTTP_HOST']) : '';
		if ($host === '' || $host !== $mine)
			return 'Cross-site request rejected.';
	}
	$sent = isset ($_POST['qp_token']) && is_string ($_POST['qp_token']) ? $_POST['qp_token'] : '';
	startSession();
	$have = isset ($_SESSION['quickphoto_csrf']) && is_string ($_SESSION['quickphoto_csrf']) ? $_SESSION['quickphoto_csrf'] : '';
	session_commit();
	if ($sent === '' || $have === '' || ! hash_equals ($have, $sent))
		return 'The security token is missing or invalid. Open the Photos tab again.';
	return NULL;
}

// php.ini shorthand ("34M") to bytes; 0 means no limit.
function plugin_quickphoto_ini_bytes ($name)
{
	$v = trim ((string) ini_get ($name));
	if ($v === '' || (int) $v <= 0)
		return 0;
	$n = (int) $v;
	switch (strtolower (substr ($v, -1)))
	{
		case 'g': $n *= 1024;
		case 'm': $n *= 1024;
		case 'k': $n *= 1024;
	}
	return $n;
}

// The uploaded "file" field as a single-file array, or NULL if absent or malformed.
function plugin_quickphoto_uploaded_file ()
{
	if (! isset ($_FILES['file']) || ! is_array ($_FILES['file']))
		return NULL;
	$f = $_FILES['file'];
	if (! isset ($f['error'], $f['tmp_name']) || is_array ($f['error']) || is_array ($f['tmp_name']))
		return NULL;
	return $f;
}

// Request part shared by both ops: POST size, CSRF token and origin checks.
// Shows the error and returns FALSE if the request must not proceed.
function plugin_quickphoto_accept_request ()
{
	$length = isset ($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
	if (qp_post_too_large ($_POST, $_FILES, $length, plugin_quickphoto_ini_bytes ('post_max_size')))
	{
		showError (qp_error_text ('too_large_post'));
		return FALSE;
	}
	if (($err = plugin_quickphoto_check_request()) !== NULL)
	{
		showError ($err);
		return FALSE;
	}
	return TRUE;
}

// Validated uploaded JPEG contents, or NULL after showing the error.
function plugin_quickphoto_read_upload ()
{
	$length = isset ($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
	$file = plugin_quickphoto_uploaded_file();
	$code = qp_validate_upload ($file, $length, plugin_quickphoto_ini_bytes ('post_max_size'));
	if ($code === NULL)
	{
		$bytes = file_get_contents ($file['tmp_name']);
		if ($bytes !== FALSE)
			return $bytes;
		$code = 'upload_error';
	}
	showError (qp_error_text ($code));
	return NULL;
}

// Roll back the open transaction, if any, without throwing.
function plugin_quickphoto_rollback ()
{
	global $dbxlink;
	try
	{
		if ($dbxlink->inTransaction())
			$dbxlink->rollBack();
	}
	catch (Throwable $e)
	{
	}
}

function plugin_quickphoto_op_add ()
{
	global $dbxlink;
	if (! plugin_quickphoto_accept_request())
		return '';
	$realm = etypeByPageno();
	$entity_id = (int) getBypassValue();
	if (($bytes = plugin_quickphoto_read_upload()) === NULL)
		return '';
	$comment = isset ($_POST['comment']) && is_string ($_POST['comment']) ? trim ($_POST['comment']) : '';
	$comment = mb_substr ($comment, 0, 1000, 'UTF-8');
	$user_name = isset ($_POST['name']) && is_string ($_POST['name']) ? $_POST['name'] : '';
	if (! qp_user_name_valid_utf8 ($user_name))
	{
		showError ('The name is not valid text. Nothing was saved.');
		return '';
	}

	$entity = spotEntity ($realm, $entity_id);
	$label = qp_name_label (isset ($entity['name']) ? (string) $entity['name'] : '', $realm, $entity_id);
	$now = time();
	$saved = '';
	for ($attempt = 1; $attempt <= 20 && $saved === ''; $attempt++)
	{
		$name = qp_user_photo_name ($user_name, $attempt);
		if ($name === NULL)
			$name = qp_photo_name ($label, $now, $attempt);
		$retry = FALSE;
		try
		{
			$dbxlink->beginTransaction();
			try
			{
				$file_id = commitAddFile ($name, 'image/jpeg', $bytes, $comment);
			}
			catch (RTDatabaseError $e)
			{
				// only a duplicate name is worth another try
				$retry = ($e->getMessage() === 'such record already exists');
				throw $e;
			}
			usePreparedInsertBlade ('FileLink', array ('file_id' => $file_id, 'entity_type' => $realm, 'entity_id' => $entity_id));
			$dbxlink->commit();
			$saved = $name;
		}
		catch (Throwable $e)
		{
			plugin_quickphoto_rollback();
			if (! $retry)
			{
				showError ('The photo could not be saved.');
				return '';
			}
		}
	}
	if ($saved === '')
		showError ('The photo could not be saved: no free file name.');
	else
		showSuccess ('Photo added: ' . qp_esc ($saved));
	return '';
}

// TRUE if the link list (as from getFileLinks) contains the entity.
function plugin_quickphoto_links_have ($links, $realm, $entity_id)
{
	foreach ($links as $link)
		if ($link['entity_type'] === $realm && (int) $link['entity_id'] === (int) $entity_id)
			return TRUE;
	return FALSE;
}

// The file cell the replace request targets, or an error text. The file must
// be linked to the current entity, be an image type the plugin lists, and the
// user must hold the core "Upload replacement" permission on it.
function plugin_quickphoto_replace_target ($realm, $entity_id)
{
	$not_here = 'This photo is not on this page. Open the Photos tab again.';
	$raw = isset ($_POST['file_id']) && is_string ($_POST['file_id']) ? $_POST['file_id'] : '';
	if (! ctype_digit ($raw) || strlen ($raw) > 10 || (int) $raw < 1)
		return $not_here;
	$file_id = (int) $raw;
	if (! plugin_quickphoto_links_have (getFileLinks ($file_id), $realm, $entity_id))
		return $not_here;
	try
	{
		$cell = spotEntity ('file', $file_id);
	}
	catch (EntityNotFoundException $e)
	{
		return $not_here;
	}
	if (! in_array ($cell['type'], plugin_quickphoto_image_types(), TRUE))
		return $not_here;
	if (! plugin_quickphoto_can_replace ($cell))
		return 'You are not allowed to replace this photo.';
	return $cell;
}

function plugin_quickphoto_op_replace ()
{
	global $dbxlink;
	if (! plugin_quickphoto_accept_request())
		return '';
	$realm = etypeByPageno();
	$entity_id = (int) getBypassValue();
	$cell = plugin_quickphoto_replace_target ($realm, $entity_id);
	if (! is_array ($cell))
	{
		showError ($cell);
		return '';
	}
	if (($bytes = plugin_quickphoto_read_upload()) === NULL)
		return '';
	$expect = array();
	foreach (array ('expected_mtime', 'expected_size', 'expected_links') as $k)
		$expect[$k] = isset ($_POST[$k]) && is_string ($_POST[$k]) ? $_POST[$k] : '';

	$file_id = (int) $cell['id'];
	try
	{
		$dbxlink->beginTransaction();
		// lock the row so that the check and the write see the same file
		$row = usePreparedSelectBlade ('SELECT mtime, size FROM File WHERE id = ? FOR UPDATE', array ($file_id))->fetch (PDO::FETCH_ASSOC);
		$links = array_values (getFileLinks ($file_id));
		if
		(
			! is_array ($row) ||
			(string) $row['mtime'] !== $expect['expected_mtime'] ||
			(string) $row['size'] !== $expect['expected_size'] ||
			! hash_equals (qp_link_fingerprint ($links), $expect['expected_links']) ||
			! plugin_quickphoto_links_have ($links, $realm, $entity_id)
		)
		{
			plugin_quickphoto_rollback();
			showError ('The photo or where it is used changed meanwhile - open the Photos tab again.');
			return '';
		}
		commitReplaceFile ($file_id, $bytes);
		usePreparedUpdateBlade ('File', array ('type' => 'image/jpeg'), array ('id' => $file_id));
		$dbxlink->commit();
	}
	catch (Throwable $e)
	{
		plugin_quickphoto_rollback();
		showError ('The photo could not be replaced.');
		return '';
	}
	showSuccess ('Photo replaced: ' . qp_esc ($cell['name']));
	return '';
}
