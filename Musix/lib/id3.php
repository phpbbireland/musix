<?php
/**
 * Minimal ID3 / Vorbis tag reader.
 *
 * Supports:
 *   - MP3: ID3v2.3 / 2.4 text frames (TIT2/TPE1/TALB/TRCK/TYER/TDRC/TCON),
 *     ID3v1 fallback (incl. genre byte), duration + bitrate estimate
 *     from first MPEG frame header.
 *   - FLAC: Vorbis comments (TITLE/ARTIST/ALBUM/DATE/TRACKNUMBER/GENRE),
 *     duration from STREAMINFO, bitrate computed from filesize/duration.
 *   - OGG: Vorbis comments inside ogg pages (best-effort).
 *
 * Returns an array with keys:
 *   title, artist, album, track_no, year, duration (seconds, int|null),
 *   bitrate (kbps, int|null), genre (string|null)
 * Any field may be null if not found.
 */
declare(strict_types=1);

namespace Musix\Id3;

// ID3v1 genre table — used both by ID3v1 (single byte at offset 127) and
// ID3v2 TCON frames containing a `(NN)` numeric reference. Indexes 0-79
// are the original spec; 80+ are Winamp extensions, included to cover
// the common cases.
const ID3V1_GENRES = [
    'Blues','Classic Rock','Country','Dance','Disco','Funk','Grunge','Hip-Hop',
    'Jazz','Metal','New Age','Oldies','Other','Pop','R&B','Rap',
    'Reggae','Rock','Techno','Industrial','Alternative','Ska','Death Metal','Pranks',
    'Soundtrack','Euro-Techno','Ambient','Trip-Hop','Vocal','Jazz+Funk','Fusion','Trance',
    'Classical','Instrumental','Acid','House','Game','Sound Clip','Gospel','Noise',
    'AlternRock','Bass','Soul','Punk','Space','Meditative','Instrumental Pop','Instrumental Rock',
    'Ethnic','Gothic','Darkwave','Techno-Industrial','Electronic','Pop-Folk','Eurodance','Dream',
    'Southern Rock','Comedy','Cult','Gangsta','Top 40','Christian Rap','Pop/Funk','Jungle',
    'Native American','Cabaret','New Wave','Psychadelic','Rave','Showtunes','Trailer','Lo-Fi',
    'Tribal','Acid Punk','Acid Jazz','Polka','Retro','Musical','Rock & Roll','Hard Rock',
    'Folk','Folk-Rock','National Folk','Swing','Fast Fusion','Bebob','Latin','Revival',
    'Celtic','Bluegrass','Avantgarde','Gothic Rock','Progressive Rock','Psychedelic Rock','Symphonic Rock','Slow Rock',
    'Big Band','Chorus','Easy Listening','Acoustic','Humour','Speech','Chanson','Opera',
    'Chamber Music','Sonata','Symphony','Booty Bass','Primus','Porn Groove','Satire','Slow Jam',
    'Club','Tango','Samba','Folklore','Ballad','Power Ballad','Rhythmic Soul','Freestyle',
    'Duet','Punk Rock','Drum Solo','A capella','Euro-House','Dance Hall',
];

function read_tags(string $path): array {
    $defaults = [
        'title'    => null,
        'artist'   => null,
        'album'    => null,
        'track_no' => null,
        'year'     => null,
        'duration' => null,
        'bitrate'  => null,
        'genre'    => null,
    ];

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    try {
        switch ($ext) {
            case 'mp3':  return array_merge($defaults, _read_mp3($path));
            case 'flac': return array_merge($defaults, _read_flac($path));
            case 'ogg':
            case 'oga':  return array_merge($defaults, _read_ogg($path));
            default:     return $defaults;
        }
    } catch (\Throwable $e) {
        return $defaults;
    }
}

// ---------- MP3 ----------

function _read_mp3(string $path): array {
    $out = [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return $out;

    try {
        // ID3v2 header
        $head = fread($fh, 10);
        if (strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
            $major = ord($head[3]);
            $flags = ord($head[5]);
            $size = _synchsafe(substr($head, 6, 4));
            $body = $size > 0 ? fread($fh, $size) : '';

            // Skip extended header if present (flag bit 6)
            $offset = 0;
            if (($flags & 0x40) && strlen($body) >= 4) {
                $extSize = $major >= 4 ? _synchsafe(substr($body, 0, 4)) : _be_int(substr($body, 0, 4));
                $offset += $extSize;
            }

            $frames = _parse_id3v2_frames(substr($body, $offset), $major);
            if (isset($frames['TIT2'])) $out['title']    = _decode_text($frames['TIT2']);
            if (isset($frames['TPE1'])) $out['artist']   = _decode_text($frames['TPE1']);
            if (isset($frames['TALB'])) $out['album']    = _decode_text($frames['TALB']);
            if (isset($frames['TRCK'])) {
                $trk = _decode_text($frames['TRCK']);
                if ($trk !== null) {
                    $n = (int)explode('/', $trk)[0];
                    if ($n > 0) $out['track_no'] = $n;
                }
            }
            if (isset($frames['TYER'])) {
                $y = (int)_decode_text($frames['TYER']);
                if ($y > 0) $out['year'] = $y;
            }
            if (isset($frames['TDRC']) && empty($out['year'])) {
                $d = _decode_text($frames['TDRC']);
                if ($d && preg_match('/(\d{4})/', $d, $m)) $out['year'] = (int)$m[1];
            }
            if (isset($frames['TCON'])) {
                $g = _decode_genre(_decode_text($frames['TCON']));
                if ($g !== null) $out['genre'] = $g;
            }
        }

        // ID3v1 fallback — only fill missing fields
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size > 128) {
            fseek($fh, $size - 128);
            $tag = fread($fh, 128);
            if (strlen($tag) === 128 && substr($tag, 0, 3) === 'TAG') {
                // ID3v1 fields have no encoding marker — spec says ISO-8859-1.
                // Run through _to_utf8 so the bytes don't blow up the INSERT.
                $get = fn($off, $len) => _to_utf8(rtrim(substr($tag, $off, $len), "\0"));
                $out['title']  = $out['title']  ?? $get(3, 30);
                $out['artist'] = $out['artist'] ?? $get(33, 30);
                $out['album']  = $out['album']  ?? $get(63, 30);
                $y = (int)$get(93, 4);
                if ($y > 0 && empty($out['year'])) $out['year'] = $y;
                if (empty($out['track_no']) && ord($tag[125]) === 0) {
                    $tn = ord($tag[126]);
                    if ($tn > 0) $out['track_no'] = $tn;
                }
                // Genre byte (offset 127). 255 = none.
                if (empty($out['genre'])) {
                    $gi = ord($tag[127]);
                    if ($gi !== 255 && isset(ID3V1_GENRES[$gi])) {
                        $out['genre'] = ID3V1_GENRES[$gi];
                    }
                }
            }
        }

        // Duration + bitrate estimate from first MPEG frame
        $info = _mp3_audio_info($fh, $size);
        $out['duration'] = $info['duration'];
        $out['bitrate']  = $info['bitrate'];
    } finally {
        fclose($fh);
    }

    return $out;
}

function _parse_id3v2_frames(string $body, int $major): array {
    $frames = [];
    $i = 0;
    $len = strlen($body);
    while ($i + 10 <= $len) {
        $id = substr($body, $i, 4);
        if (!preg_match('/^[A-Z0-9]{4}$/', $id)) break;
        $sizeBytes = substr($body, $i + 4, 4);
        $size = $major >= 4 ? _synchsafe($sizeBytes) : _be_int($sizeBytes);
        $i += 10;
        if ($size <= 0 || $i + $size > $len) break;
        $frames[$id] = substr($body, $i, $size);
        $i += $size;
    }
    return $frames;
}

function _decode_text(string $raw): ?string {
    if ($raw === '') return null;
    $enc = ord($raw[0]);
    $payload = substr($raw, 1);
    switch ($enc) {
        case 0: // ISO-8859-1
            $s = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $payload);
            break;
        case 1: // UTF-16 with BOM
            $s = @iconv('UTF-16', 'UTF-8//IGNORE', $payload);
            break;
        case 2: // UTF-16BE
            $s = @iconv('UTF-16BE', 'UTF-8//IGNORE', $payload);
            break;
        case 3: // UTF-8
        default:
            $s = $payload;
    }
    if ($s === false) return null;
    return _to_utf8($s);
}

/**
 * Coerce a tag value to clean UTF-8 regardless of where the bytes came from.
 *
 * The field-level decoders above already handle ID3v2's declared encodings,
 * but two places leak non-UTF-8 bytes:
 *   - ID3v1 (fixed-width ASCII fields, frequently Latin-1 / Windows-1252 in
 *     the wild — that's where the `0xFA = ú` insert errors come from).
 *   - Vorbis comments are spec'd as UTF-8 but some files lie.
 *
 * mb_convert_encoding's auto-detect tries the candidate list in order. If
 * the input is already valid UTF-8 it's returned unchanged. Otherwise it
 * falls back to ISO-8859-1, then Windows-1252.
 */
function _to_utf8(?string $s): ?string {
    if ($s === null) return null;
    $s = rtrim($s, "\0");
    $s = trim($s);
    if ($s === '') return null;

    // Fast path: already valid UTF-8.
    if (preg_match('//u', $s)) return $s;

    // Prefer mbstring's auto-detect when available — it's the cleanest fix.
    if (function_exists('mb_convert_encoding')) {
        $out = @mb_convert_encoding($s, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        if (is_string($out) && $out !== '') return $out;
    }

    // Fallback: assume Windows-1252 (a superset of ISO-8859-1 — covers
    // smart quotes etc. too). Stripping bad bytes is preferable to dropping
    // the whole row.
    if (function_exists('iconv')) {
        $out = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        if (is_string($out) && $out !== '') return $out;
    }

    // Last-ditch: scrub anything that isn't ASCII-printable.
    $out = preg_replace('/[^\x20-\x7E]/', '', $s);
    return $out !== '' ? $out : null;
}

function _synchsafe(string $b): int {
    if (strlen($b) !== 4) return 0;
    return ((ord($b[0]) & 0x7F) << 21)
         | ((ord($b[1]) & 0x7F) << 14)
         | ((ord($b[2]) & 0x7F) << 7)
         |  (ord($b[3]) & 0x7F);
}

function _be_int(string $b): int {
    $n = 0;
    foreach (str_split($b) as $c) $n = ($n << 8) | ord($c);
    return $n;
}

/**
 * Read MP3 audio info from the first MPEG frame header.
 *
 * Returns ['duration' => ?int seconds, 'bitrate' => ?int kbps].
 * Bitrate is from the frame header (CBR-accurate, VBR-approximate);
 * duration is a CBR estimate (audio_bytes * 8 / bitrate). For VBR
 * files this is close enough for browsing — exact VBR duration would
 * need a Xing/VBRI header parse.
 */
function _mp3_audio_info($fh, int $fileSize): array {
    $out = ['duration' => null, 'bitrate' => null];

    // Skip past ID3v2 if present
    rewind($fh);
    $head = fread($fh, 10);
    $skip = 0;
    if (strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
        $skip = 10 + _synchsafe(substr($head, 6, 4));
    }
    fseek($fh, $skip);

    // Find first frame sync (0xFFEx)
    $buf = fread($fh, 4096);
    $offset = 0;
    while ($offset < strlen($buf) - 4) {
        if (ord($buf[$offset]) === 0xFF && (ord($buf[$offset+1]) & 0xE0) === 0xE0) break;
        $offset++;
    }
    if ($offset >= strlen($buf) - 4) return $out;
    $hdr = substr($buf, $offset, 4);

    $b1 = ord($hdr[1]);
    $b2 = ord($hdr[2]);
    $versionId = ($b1 >> 3) & 0x03;   // 3=MPEG1, 2=MPEG2, 0=MPEG2.5
    $layerDesc = ($b1 >> 1) & 0x03;   // 1=Layer III
    $bitrateIdx = ($b2 >> 4) & 0x0F;
    $sampleIdx  = ($b2 >> 2) & 0x03;

    $bitrates = [
        // MPEG1 Layer III
        '1_1' => [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320, 0],
        // MPEG2 / 2.5 Layer III
        '2_1' => [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160, 0],
    ];
    $rates = [
        3 => [44100, 48000, 32000, 0],
        2 => [22050, 24000, 16000, 0],
        0 => [11025, 12000, 8000, 0],
    ];

    $key = $versionId === 3 ? '1_1' : '2_1';
    $bitrate = $bitrates[$key][$bitrateIdx] ?? 0;
    $sampleRate = $rates[$versionId][$sampleIdx] ?? 0;
    if ($bitrate <= 0 || $sampleRate <= 0) return $out;

    $out['bitrate'] = $bitrate;

    // bytes for audio = filesize - id3v2 - id3v1 (128 if present)
    $audioBytes = $fileSize - $skip;
    fseek($fh, $fileSize - 128);
    if (fread($fh, 3) === 'TAG') $audioBytes -= 128;

    // CBR estimate: bitrate is in kbps
    $seconds = ($audioBytes * 8) / ($bitrate * 1000);
    $sec = (int)round($seconds);
    if ($sec > 0) $out['duration'] = $sec;
    return $out;
}

/**
 * Normalise an ID3v2 TCON genre value.
 *
 * TCON can be:
 *   - "Rock" (plain)
 *   - "(17)" (numeric ID3v1 reference)
 *   - "(17)Rock" (both — prefer the literal)
 *   - "(RX)" / "(CR)" — special "Remix"/"Cover" markers, ignored
 *   - "Hip-Hop / Rap" — multi-genre, we keep the first
 */
function _decode_genre(?string $raw): ?string {
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;

    // Strip leading "(NN)" prefix, then keep what's left if any. If only
    // the prefix is present, look up the numeric in the v1 table.
    if (preg_match('/^\(([0-9]+|RX|CR)\)\s*(.*)$/i', $raw, $m)) {
        $literal = trim($m[2]);
        if ($literal !== '') return _to_utf8($literal);
        if (ctype_digit($m[1])) {
            $idx = (int)$m[1];
            if (isset(ID3V1_GENRES[$idx])) return ID3V1_GENRES[$idx];
        }
        return null;
    }

    // Bare-numeric form — some taggers store TCON as just "13" with no
    // parens. Treat purely-digits values as ID3v1 indexes too.
    if (ctype_digit($raw)) {
        $idx = (int)$raw;
        if (isset(ID3V1_GENRES[$idx])) return ID3V1_GENRES[$idx];
        return null;
    }

    // Multi-genre separators — split on common delimiters and take the first.
    foreach (['; ', ' / ', '/', ';', ','] as $sep) {
        if (strpos($raw, $sep) !== false) {
            $first = trim(explode($sep, $raw, 2)[0]);
            if ($first !== '') return _to_utf8($first);
        }
    }

    return _to_utf8($raw);
}

// ---------- FLAC ----------

/**
 * Some FLAC files in the wild have an ID3v2 tag prepended to the stream
 * (non-spec, but common from MP3-era taggers like Mp3tag). The reference
 * libFLAC tolerates it; most browser decoders refuse anything that doesn't
 * start with 'fLaC' magic. We do the same job here so duration parsing
 * succeeds, and `stream.php` strips the same prefix on the wire so the
 * browser sees a clean stream.
 *
 * Returns the byte length of the ID3v2 prefix (0 when none), and leaves
 * the file handle positioned at the start of the actual FLAC stream.
 */
function flac_id3v2_prefix_size(string $path): int {
    $fh = @fopen($path, 'rb');
    if (!$fh) return 0;
    try {
        $head = fread($fh, 10);
        if (strlen($head) !== 10 || substr($head, 0, 3) !== 'ID3') return 0;
        $flags = ord($head[5]);
        $size  = _synchsafe(substr($head, 6, 4));
        // 10-byte header + body + optional 10-byte footer (flag bit 4).
        return 10 + $size + (($flags & 0x10) ? 10 : 0);
    } finally {
        fclose($fh);
    }
}

function _read_flac(string $path): array {
    $out = [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return $out;
    try {
        // Skip any non-spec ID3v2 prefix before the FLAC magic. See
        // flac_id3v2_prefix_size() above.
        $head = fread($fh, 10);
        if (strlen($head) === 10 && substr($head, 0, 3) === 'ID3') {
            $flags  = ord($head[5]);
            $size   = _synchsafe(substr($head, 6, 4));
            $extra  = $size + (($flags & 0x10) ? 10 : 0);
            if ($extra > 0) fseek($fh, $extra, SEEK_CUR);
        } else {
            rewind($fh);
        }
        if (fread($fh, 4) !== 'fLaC') return $out;
        while (!feof($fh)) {
            $hdr = fread($fh, 4);
            if (strlen($hdr) < 4) break;
            $b0 = ord($hdr[0]);
            $isLast = ($b0 & 0x80) !== 0;
            $type   = $b0 & 0x7F;
            $size   = (ord($hdr[1]) << 16) | (ord($hdr[2]) << 8) | ord($hdr[3]);
            $block  = $size > 0 ? fread($fh, $size) : '';

            if ($type === 0 && strlen($block) >= 18) {
                // STREAMINFO: bytes 10..17 contain sample rate (20 bits) and total samples (36 bits)
                $sampleRate = (ord($block[10]) << 12)
                            | (ord($block[11]) << 4)
                            | ((ord($block[12]) >> 4) & 0x0F);
                $totalHigh = ord($block[13]) & 0x0F;
                $totalLow  = (ord($block[14]) << 24)
                           | (ord($block[15]) << 16)
                           | (ord($block[16]) << 8)
                           |  ord($block[17]);
                // Use floats to avoid 32-bit issues on edge platforms
                $totalSamples = ($totalHigh * 4294967296.0) + (float)$totalLow;
                if ($sampleRate > 0 && $totalSamples > 0) {
                    $out['duration'] = (int)round($totalSamples / $sampleRate);
                }
            } elseif ($type === 4) {
                // VORBIS_COMMENT
                $vc = _parse_vorbis_comment($block);
                $out = _merge_vorbis($out, $vc);
            }

            if ($isLast) break;
        }
    } finally {
        fclose($fh);
    }

    // FLAC is variable-rate; compute average bitrate from filesize/duration.
    // Good enough for "is this a 320kbps rip or a 1411kbps lossless?" UI.
    if (!empty($out['duration'])) {
        clearstatcache(true, $path);
        $fsize = @filesize($path);
        if ($fsize !== false && $fsize > 0) {
            $kbps = (int)round(($fsize * 8) / ($out['duration'] * 1000));
            if ($kbps > 0) $out['bitrate'] = $kbps;
        }
    }
    return $out;
}

// ---------- OGG ----------

function _read_ogg(string $path): array {
    $out = [];
    $data = @file_get_contents($path, false, null, 0, 65536);
    if ($data === false) return $out;
    $pos = strpos($data, "\x03vorbis");
    if ($pos === false) return $out;
    $vc = _parse_vorbis_comment(substr($data, $pos + 7));
    return _merge_vorbis($out, $vc);
}

function _parse_vorbis_comment(string $block): array {
    $i = 0;
    $len = strlen($block);
    if ($i + 4 > $len) return [];
    $vendorLen = unpack('V', substr($block, $i, 4))[1];
    $i += 4 + $vendorLen;
    if ($i + 4 > $len) return [];
    $count = unpack('V', substr($block, $i, 4))[1];
    $i += 4;
    $tags = [];
    for ($n = 0; $n < $count; $n++) {
        if ($i + 4 > $len) break;
        $clen = unpack('V', substr($block, $i, 4))[1];
        $i += 4;
        if ($i + $clen > $len) break;
        $entry = substr($block, $i, $clen);
        $i += $clen;
        $eq = strpos($entry, '=');
        if ($eq === false) continue;
        $k = strtoupper(substr($entry, 0, $eq));
        $v = substr($entry, $eq + 1);
        $tags[$k] = $v;
    }
    return $tags;
}

function _merge_vorbis(array $out, array $vc): array {
    // Vorbis comments are spec'd as UTF-8, but in practice files lie —
    // run them through _to_utf8 too so a malformed FLAC/OGG can't crash
    // the scan.
    if (isset($vc['TITLE']))  $out['title']  = _to_utf8($vc['TITLE']);
    if (isset($vc['ARTIST'])) $out['artist'] = _to_utf8($vc['ARTIST']);
    if (isset($vc['ALBUM']))  $out['album']  = _to_utf8($vc['ALBUM']);
    if (isset($vc['TRACKNUMBER'])) {
        $n = (int)explode('/', $vc['TRACKNUMBER'])[0];
        if ($n > 0) $out['track_no'] = $n;
    }
    if (isset($vc['DATE']) && preg_match('/(\d{4})/', $vc['DATE'], $m)) {
        $out['year'] = (int)$m[1];
    }
    if (isset($vc['GENRE'])) {
        $g = _decode_genre($vc['GENRE']);
        if ($g !== null) $out['genre'] = $g;
    }
    return $out;
}

// ---------- Embedded picture extraction ----------
//
// Extract embedded album art from an audio file. Supports:
//   - MP3 ID3v2.3/2.4 APIC frames
//   - FLAC METADATA_BLOCK_PICTURE blocks (type 6) + Vorbis-comment
//     METADATA_BLOCK_PICTURE fallback for older FLACs
//   - OGG Vorbis METADATA_BLOCK_PICTURE in the comment header (base64)
//
// Returns ['mime' => string, 'ext' => string, 'data' => bytes] or null
// when the file has no embedded picture, the format isn't supported, or
// the picture is in a mime type we won't serve (only jpg/png/gif/webp
// pass through — the rest are dropped).
//
// When multiple pictures exist we prefer FLAC/APIC picture_type 3
// ("Cover (front)"); otherwise the first usable one.

function extract_picture(string $path): ?array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    try {
        switch ($ext) {
            case 'mp3':  return _extract_mp3_apic($path);
            case 'flac': return _extract_flac_picture($path);
            case 'ogg':
            case 'oga':  return _extract_ogg_picture($path);
            default:     return null;
        }
    } catch (\Throwable $e) {
        return null;
    }
}

const _SUPPORTED_PICTURE_MIMES = [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

function _normalize_picture_mime(string $mime): ?string {
    $mime = strtolower(trim($mime));
    if ($mime === '') return null;
    // Some old taggers stored 3-char "JPG"/"PNG" instead of full mime.
    if ($mime === 'jpg' || $mime === 'jpeg') return 'image/jpeg';
    if ($mime === 'png') return 'image/png';
    if ($mime === 'gif') return 'image/gif';
    if ($mime === 'webp') return 'image/webp';
    return $mime;
}

function _pick_picture(array $candidates): ?array {
    if (!$candidates) return null;
    // Prefer picture_type 3 (Cover (front)) when present.
    foreach ($candidates as $c) {
        if (($c['type'] ?? 0) === 3) return _finalize_picture($c);
    }
    return _finalize_picture($candidates[0]);
}

function _finalize_picture(array $c): ?array {
    $mime = _normalize_picture_mime((string)($c['mime'] ?? ''));
    if ($mime === null) return null;
    $ext = _SUPPORTED_PICTURE_MIMES[$mime] ?? null;
    if ($ext === null) return null;
    if (empty($c['data']) || strlen($c['data']) < 16) return null;
    return ['mime' => $mime, 'ext' => $ext, 'data' => $c['data']];
}

// MP3: walk ID3v2 frames, collect every APIC, parse them, pick best.
function _extract_mp3_apic(string $path): ?array {
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        $head = fread($fh, 10);
        if (strlen($head) !== 10 || substr($head, 0, 3) !== 'ID3') return null;
        $major = ord($head[3]);
        if ($major < 3) return null; // v2.2's PIC frame uses a different layout
        $flags = ord($head[5]);
        $size  = _synchsafe(substr($head, 6, 4));
        if ($size <= 0) return null;
        $body = fread($fh, $size);
        if (strlen($body) !== $size) return null;

        $offset = 0;
        if (($flags & 0x40) && strlen($body) >= 4) {
            $extSize = $major >= 4 ? _synchsafe(substr($body, 0, 4)) : _be_int(substr($body, 0, 4));
            $offset += $extSize;
        }
        $body = substr($body, $offset);

        $candidates = [];
        $i = 0;
        $len = strlen($body);
        while ($i + 10 <= $len) {
            $id = substr($body, $i, 4);
            if (!preg_match('/^[A-Z0-9]{4}$/', $id)) break;
            $fsize = $major >= 4 ? _synchsafe(substr($body, $i + 4, 4)) : _be_int(substr($body, $i + 4, 4));
            $i += 10;
            if ($fsize <= 0 || $i + $fsize > $len) break;
            if ($id === 'APIC') {
                $apic = _parse_apic(substr($body, $i, $fsize));
                if ($apic) $candidates[] = $apic;
            }
            $i += $fsize;
        }
        return _pick_picture($candidates);
    } finally {
        fclose($fh);
    }
}

function _parse_apic(string $body): ?array {
    if ($body === '') return null;
    $enc = ord($body[0]);
    $body = substr($body, 1);
    // MIME type is always null-terminated ASCII regardless of $enc.
    $nul = strpos($body, "\0");
    if ($nul === false) return null;
    $mime = substr($body, 0, $nul);
    $body = substr($body, $nul + 1);
    if ($body === '') return null;
    $type = ord($body[0]);
    $body = substr($body, 1);
    // Description terminator depends on encoding (UTF-16 uses double-NUL on
    // even boundary; ISO-8859-1 / UTF-8 uses single NUL).
    if ($enc === 1 || $enc === 2) {
        $i = 0;
        $len = strlen($body);
        while ($i + 1 < $len) {
            if ($body[$i] === "\0" && $body[$i + 1] === "\0") break;
            $i += 2;
        }
        if ($i + 1 >= $len) return null;
        $body = substr($body, $i + 2);
    } else {
        $nul = strpos($body, "\0");
        if ($nul === false) return null;
        $body = substr($body, $nul + 1);
    }
    if ($body === '') return null;
    return ['type' => $type, 'mime' => $mime, 'data' => $body];
}

// FLAC: walk metadata blocks. Type 6 = PICTURE block; type 4 = VORBIS_COMMENT
// where some old encoders stash METADATA_BLOCK_PICTURE in base64 instead.
function _extract_flac_picture(string $path): ?array {
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    try {
        if (fread($fh, 4) !== 'fLaC') return null;
        $candidates = [];
        $vorbisBlock = null;
        while (!feof($fh)) {
            $hdr = fread($fh, 4);
            if (strlen($hdr) < 4) break;
            $b0     = ord($hdr[0]);
            $isLast = ($b0 & 0x80) !== 0;
            $type   = $b0 & 0x7F;
            $size   = (ord($hdr[1]) << 16) | (ord($hdr[2]) << 8) | ord($hdr[3]);
            if ($type === 6 && $size > 0) {
                $block = fread($fh, $size);
                $pic = _parse_flac_picture_body($block);
                if ($pic) $candidates[] = $pic;
            } elseif ($type === 4 && $size > 0) {
                $vorbisBlock = fread($fh, $size);
            } elseif ($size > 0) {
                fseek($fh, $size, SEEK_CUR);
            }
            if ($isLast) break;
        }
        if (!$candidates && $vorbisBlock !== null) {
            $vc = _parse_vorbis_comment_multi($vorbisBlock);
            foreach ($vc['METADATA_BLOCK_PICTURE'] ?? [] as $b64) {
                $bin = base64_decode($b64, true);
                if ($bin !== false) {
                    $pic = _parse_flac_picture_body($bin);
                    if ($pic) $candidates[] = $pic;
                }
            }
        }
        return _pick_picture($candidates);
    } finally {
        fclose($fh);
    }
}

// FLAC PICTURE block layout (also used inside METADATA_BLOCK_PICTURE base64):
//   u32 type, u32 mime_len, mime, u32 desc_len, desc,
//   u32 width, u32 height, u32 depth, u32 colors, u32 data_len, data
function _parse_flac_picture_body(string $block): ?array {
    $len = strlen($block);
    if ($len < 32) return null;
    $i = 0;
    $type = _be_int(substr($block, $i, 4)); $i += 4;
    $mimeLen = _be_int(substr($block, $i, 4)); $i += 4;
    if ($mimeLen < 0 || $i + $mimeLen > $len) return null;
    $mime = substr($block, $i, $mimeLen); $i += $mimeLen;
    if ($i + 4 > $len) return null;
    $descLen = _be_int(substr($block, $i, 4)); $i += 4;
    if ($descLen < 0 || $i + $descLen > $len) return null;
    $i += $descLen;
    if ($i + 16 > $len) return null;
    $i += 16; // width, height, depth, colors
    if ($i + 4 > $len) return null;
    $dataLen = _be_int(substr($block, $i, 4)); $i += 4;
    if ($dataLen <= 0 || $i + $dataLen > $len) return null;
    return ['type' => $type, 'mime' => $mime, 'data' => substr($block, $i, $dataLen)];
}

// OGG Vorbis: comment header lives in the second packet; with embedded art
// it can span multiple OGG pages, so we depacketize first then look for the
// "\x03vorbis" magic.
function _extract_ogg_picture(string $path): ?array {
    // Cap reads at 4MB — covers nearly all single-image embeds without
    // pulling whole multi-MB audio files into memory for nothing.
    $data = @file_get_contents($path, false, null, 0, 4 * 1024 * 1024);
    if ($data === false) return null;
    $body = _ogg_depacketize($data);
    $pos = strpos($body, "\x03vorbis");
    if ($pos === false) return null;
    $vc = _parse_vorbis_comment_multi(substr($body, $pos + 7));
    $candidates = [];
    foreach ($vc['METADATA_BLOCK_PICTURE'] ?? [] as $b64) {
        $bin = base64_decode($b64, true);
        if ($bin !== false) {
            $pic = _parse_flac_picture_body($bin);
            if ($pic) $candidates[] = $pic;
        }
    }
    return _pick_picture($candidates);
}

// Concatenate the page bodies of an OGG bitstream so a single packet that
// got split across pages is re-assembled. Walks until first non-OggS or
// end-of-buffer.
function _ogg_depacketize(string $data): string {
    $out = '';
    $i = 0;
    $len = strlen($data);
    while ($i + 27 <= $len) {
        if (substr($data, $i, 4) !== 'OggS') break;
        $segCount = ord($data[$i + 26]);
        if ($i + 27 + $segCount > $len) break;
        $bodyLen = 0;
        for ($s = 0; $s < $segCount; $s++) {
            $bodyLen += ord($data[$i + 27 + $s]);
        }
        if ($i + 27 + $segCount + $bodyLen > $len) break;
        $out .= substr($data, $i + 27 + $segCount, $bodyLen);
        $i += 27 + $segCount + $bodyLen;
    }
    return $out;
}

// Like _parse_vorbis_comment but keeps every value for repeated keys
// (METADATA_BLOCK_PICTURE legitimately repeats — front/back/booklet etc.).
function _parse_vorbis_comment_multi(string $block): array {
    $i = 0;
    $len = strlen($block);
    if ($i + 4 > $len) return [];
    $vendorLen = unpack('V', substr($block, $i, 4))[1];
    $i += 4 + $vendorLen;
    if ($i + 4 > $len) return [];
    $count = unpack('V', substr($block, $i, 4))[1];
    $i += 4;
    $tags = [];
    for ($n = 0; $n < $count; $n++) {
        if ($i + 4 > $len) break;
        $clen = unpack('V', substr($block, $i, 4))[1];
        $i += 4;
        if ($i + $clen > $len) break;
        $entry = substr($block, $i, $clen);
        $i += $clen;
        $eq = strpos($entry, '=');
        if ($eq === false) continue;
        $k = strtoupper(substr($entry, 0, $eq));
        $v = substr($entry, $eq + 1);
        $tags[$k][] = $v;
    }
    return $tags;
}
