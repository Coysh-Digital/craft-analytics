<?php

namespace coyshdigital\craftanalytics\write;

/**
 * How much is sitting in the spool, unread.
 *
 * Read-only and cheap: one `filesize()`, not a parse of the file. That is
 * enough to tell a "no traffic yet" screen apart from a "traffic arrived but
 * hasn't been drained" one without paying to open a file that might be large.
 */
final class SpoolStatus
{
    public ?SpoolWriter $spool = null;

    public function backlogBytes(): int
    {
        $path = $this->spool()->spoolPath();

        if (!is_file($path)) {
            return 0;
        }

        clearstatcache(true, $path);
        $size = filesize($path);

        return $size === false ? 0 : $size;
    }

    public function hasBacklog(): bool
    {
        return $this->backlogBytes() > 0;
    }

    /**
     * Whether a previous pass left a claimed file part-way through.
     *
     * The automatic drain applies one slice per pass and leaves the rest of
     * the file claimed, so "nothing in the live spool" no longer means
     * "nothing to drain": the backlog may be sitting in a `.processing` file
     * waiting for the next pass to resume it.
     */
    public function hasClaimed(): bool
    {
        return (glob($this->spool()->spoolDir() . DIRECTORY_SEPARATOR . '*.processing') ?: []) !== [];
    }

    private function spool(): SpoolWriter
    {
        return $this->spool ??= new SpoolWriter();
    }
}
