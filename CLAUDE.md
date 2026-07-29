# audit-trail-bundle

A Symfony bundle recording who changed what and when, through Doctrine's `onFlush`, in the same
transaction as the change it describes.

Read [AGENTS.md](AGENTS.md) before making a change: it holds the repository map, the four rules that
decide most design questions, a recipe per kind of change, and the traps that have already cost time
here — chief among them that a listener must never query during a flush, and that the bundle must
never learn what a "user" is.

@AGENTS.md
