# T-164 — Invite links that can be attributed, and people to follow on day one

- **Phase:** GROW · **Estimate:** S · **Status:** see tasks.json
- **Depends on:** T-163
- **Target paths:**
  `apps/api/database/migrations/`,
  `apps/api/app/Models/Invitation.php`,
  `apps/api/app/Http/Controllers/Api/V1/InviteController.php`,
  `apps/api/app/Http/Controllers/Api/V1/FollowController.php`,
  `apps/mobile/app/(main)/`

## Why

Instagram cannot tell a third-party app who your friends are: Basic Display
reached end-of-life on 2024-12-04, and the Graph API reaches only a professional
account's own data, with no friend-graph permission since 2018. So the owner's
"show me my Instagram friends who are here" is not buildable as described.

Owner decision D3 (08 section 9.3) takes the three routes that are: invite links
first (this task), opt-in contacts matching next (T-165), and -- available with no
permission at all -- suggesting the creators whose reels the user has already
shared. That last one fills an empty follow list on day one from data already
held.

`Invitation` today has no code and no `accepted_at`, and `users` has no
`referred_by`, so even an invite that works cannot be attributed.

## Acceptance

- An invitation carries a single-use code and records `accepted_at`; `users.referred_by` is set on acceptance and never overwritten afterwards
- An invite link opens the app when installed and a web landing page when not (built on T-163)
- Creator suggestions are derived from the viewer's own shares, exclude already-followed accounts, and require no external permission
- A user with zero shares gets a non-empty, non-embarrassing suggestion surface or none at all -- never a broken list
- Invite codes cannot be enumerated, and an expired or spent code fails closed
- Invitee email retention respects the cap and disclosure from T-125

## Notes

Filed 2026-09-03 from owner decision D3. Verified constraint: Instagram exposes no follower/following data to third parties (Meta Instagram Platform docs; Basic Display EOL 2024-12-04).
