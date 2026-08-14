@extends('legal.layout')

@section('title', 'Privacy Policy')
@section('summary', 'What data Reelmap collects, what it is used for, who it is shared with, and how to get a copy or delete it.')
@section('updated-label', 'In effect since')
@section('toc-label', 'Contents')
@section('other-doc', 'Terms of Service →')
@section('footer-contact', 'Privacy enquiries: {{ $contact }}')

@section('toc')
  <li><a href="#controller">Who is responsible for your data</a></li>
  <li><a href="#what-we-collect">What we collect</a></li>
  <li><a href="#why">What we use it for, and on what legal basis</a></li>
  <li><a href="#ai">How what you share gets analysed</a></li>
  <li><a href="#permissions">Location, camera and notifications</a></li>
  <li><a href="#third-parties">Who we share it with</a></li>
  <li><a href="#tracking">Advertising and tracking</a></li>
  <li><a href="#retention">How long we keep it</a></li>
  <li><a href="#rights">Your rights</a></li>
  <li><a href="#deletion">Deleting your account</a></li>
  <li><a href="#transfers">International transfers</a></li>
  <li><a href="#children">Children</a></li>
  <li><a href="#security">Security</a></li>
  <li><a href="#changes">Changes to this policy</a></li>
@endsection

@section('body')
  <h2 id="controller">Who is responsible for your data</h2>
  <p>
    Reelmap is built and operated by <strong>{{ $controller }}</strong>, based in {{ $domicile }}. For the
    purposes of Uruguayan Law No. 18.331 on the Protection of Personal Data and, where it applies, the
    European Union's General Data Protection Regulation (GDPR), they are the <strong>data controller</strong>
    for everything described here.
  </p>
  <p>
    For any question, complaint or request about your data, write to
    <a href="mailto:{{ $contact }}">{{ $contact }}</a>. It is the same address we use for moderation and
    support, and it is monitored.
  </p>

  <h2 id="what-we-collect">What we collect</h2>
  <p>
    Only what the app needs in order to work. We do not buy data and we do not enrich it from outside sources.
  </p>

  <h3>Account</h3>
  <p>
    Your email address, name, username and password. The password is stored <em>hashed</em> — we neither keep
    nor can read it. If you turn on two-step verification, the secret and your recovery codes are stored
    encrypted.
  </p>

  <h3>Profile (optional)</h3>
  <p>
    Photo, bio, date of birth, country, language, and your lists of favourite topics and foods. All of it is
    optional, you edit it yourself, and you can clear it whenever you like. Your profile can be public or
    private — that is your choice.
  </p>

  <h3>What you create in the app</h3>
  <p>
    The links to posts you share and the place data extracted from them, your reviews, your lists, your
    private tags on a place, the corrections you suggest about a venue (including any free-text note you
    write), the reports you file, and who you follow.
  </p>

  <h3>Linked social accounts</h3>
  <p>
    If you link an Instagram account, we store that account's identifier and the access token the platform
    issues, so we can read your own posts. You can unlink it at any time; the token is deleted at that moment.
  </p>

  <h3>Device</h3>
  <p>
    Your push notification token, device name and app version, so we can tell you when an analysis finishes
    or when someone interacts with you.
  </p>

  <h3>Payments and redemptions</h3>
  <p>
    If you redeem an offer or get paid as a creator, we store the redemption record and the corresponding
    ledger entries. <strong>We do not store card details.</strong> Identity verification and payouts are
    handled by Stripe through a Stripe Connect Express account that belongs to you, and that data stays with
    Stripe.
  </p>

  <h3>Diagnostics</h3>
  <p>
    When something breaks, an error report is sent to Sentry so it can be fixed.
  </p>
  <div class="note">
    <p>
      That report carries <strong>no personal data by construction, not by promise</strong>: sending personal
      information and query parameters is switched off in the code and is deliberately not configurable by
      environment variable, precisely so nobody can switch it on in the middle of an incident. What Sentry
      receives is the stack trace and a few technical identifiers.
    </p>
  </div>

  <h3>What we do not collect</h3>
  <p>
    We never ask for or receive your contacts, your browsing history, health data, or bank or card account
    numbers.
  </p>

  <h2 id="why">What we use it for, and on what legal basis</h2>
  <div class="table-wrap" tabindex="0" role="region" aria-label="What we use your data for, and on what legal basis">
  <table>
    <thead>
      <tr><th>Purpose</th><th>Data used</th><th>Legal basis</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Creating your account, signing you in, running the service</td>
        <td>Email, username, password</td>
        <td>Performance of a contract</td>
      </tr>
      <tr>
        <td>Showing your public profile and your contributions</td>
        <td>Profile, content you create</td>
        <td>Performance of a contract</td>
      </tr>
      <tr>
        <td>Analysing the posts you share and locating the places in them</td>
        <td>Shared links, the post's video and audio</td>
        <td>Performance of a contract</td>
      </tr>
      <tr>
        <td>Centring the map where you are</td>
        <td>Approximate device location</td>
        <td>Consent (system permission)</td>
      </tr>
      <tr>
        <td>Telling you about things that concern you</td>
        <td>Push token</td>
        <td>Consent (system permission)</td>
      </tr>
      <tr>
        <td>Issuing and verifying redemptions, and paying creators</td>
        <td>Redemption records and ledger entries</td>
        <td>Contract and legal obligation</td>
      </tr>
      <tr>
        <td>Moderating abuse, preventing fraud, keeping the service up</td>
        <td>Reports, blocks, technical logs</td>
        <td>Legitimate interest</td>
      </tr>
    </tbody>
  </table>
  </div>

  <h2 id="ai">How what you share gets analysed</h2>
  <p>
    When you share a post with Reelmap, here is exactly what happens: we download that post's public content,
    extract a handful of video frames and the audio, transcribe the audio to text, and hand those frames and
    that transcript to an AI model that works out which venue is being talked about. We then look that venue
    up in Google Places to get its address and coordinates.
  </p>
  <div class="note">
    <p>
      The model doing that work runs <strong>on our own infrastructure</strong> whenever it is available. When
      it is not — or when you have picked a specific model in Settings — the analysis runs through
      <strong>OpenRouter</strong>, a third-party provider, and in that case the frames and the transcript
      leave our servers for that provider. You can see and choose the model under
      <em>Settings → Analysis model</em>.
    </p>
  </div>
  <p>
    The original video is deleted as soon as the analysis finishes and, in any case, within
    <strong>72 hours</strong>. We keep only the frames and thumbnails that serve to identify the place.
    Original posts are never re-hosted: they are always shown linked or embedded from the platform they live on.
  </p>

  <h2 id="permissions">Location, camera and notifications</h2>
  <p>All three permissions are optional, and the app works without them.</p>
  <ul>
    <li>
      <strong>Location.</strong> Only while you are using the app, never in the background, to centre the map
      where you are and to power the "locate me" button. <strong>Your location is not stored against your
      account</strong> — it is used in the moment and not recorded. If you decline, the map opens on a
      default region.
    </li>
    <li>
      <strong>Camera.</strong> Solely to scan a redemption QR code at the venue. We do not request photo
      library access. If you would rather not grant it, the code can always be typed in by hand.
    </li>
    <li>
      <strong>Notifications.</strong> To tell you when something you shared has finished analysing, or when
      there is activity on your contributions.
    </li>
  </ul>

  <h2 id="third-parties">Who we share it with</h2>
  <p>
    We do not sell your data and we do not hand it to third parties for commercial purposes. We share it only
    with the providers that make the service work, and only the minimum each one needs:
  </p>
  <ul>
    <li><strong>Google Places</strong> — to resolve a venue's address and coordinates.</li>
    <li><strong>Instagram, YouTube, TikTok and X</strong> — to read the public data of the post you shared.</li>
    <li><strong>OpenRouter</strong> — when analysis does not run on our own infrastructure (see above).</li>
    <li><strong>Stripe</strong> — identity verification and creator payouts.</li>
    <li><strong>Expo, Apple and Google</strong> — to deliver push notifications to your device.</li>
    <li><strong>Sentry</strong> — error reports, with no personal data.</li>
    <li><strong>Our email provider</strong> — for verification codes and account notices.</li>
    <li><strong>Our hosting and storage providers</strong> — where the database and files live.</li>
  </ul>
  <p>We may also disclose data where a court order or a legal obligation requires it.</p>

  <h2 id="tracking">Advertising and tracking</h2>
  <div class="note">
    <p>
      Reelmap does <strong>no advertising tracking</strong>. There is no advertising SDK, no third-party
      analytics, and no data shared with data brokers. We do not link your activity here with your activity
      in other apps or websites.
    </p>
  </div>

  <h2 id="retention">How long we keep it</h2>
  <ul>
    <li><strong>Original video and audio:</strong> up to 72 hours after analysis. If an analysis gets stuck, 168 hours at the absolute outside.</li>
    <li><strong>Raw payloads from the source platform:</strong> 90 days, after which they are dropped and only the already-extracted fields remain.</li>
    <li><strong>Your data export:</strong> the archive is deleted after 7 days and the download link expires after 24 hours.</li>
    <li><strong>Account and content:</strong> for as long as your account exists. If you delete it, the next section applies.</li>
    <li><strong>Redemption, payout and ledger records:</strong> retained even after you delete your account, as a legal and accounting obligation.</li>
  </ul>

  <h2 id="rights">Your rights</h2>
  <p>
    You may exercise, free of charge, your rights of <strong>access, rectification, erasure, portability and
    objection</strong>, and withdraw any permission you have granted at any time.
  </p>
  <p>Most of it is handled inside the app, without writing to anyone:</p>
  <ul>
    <li><strong>Access and portability:</strong> <em>Settings → Privacy &amp; data → Get a copy of my data</em>. We build you an archive containing your profile, shared posts, places, lists, tags, reviews, suggested corrections, reports, follows, notifications, devices, redemptions and account movements.</li>
    <li><strong>Rectification:</strong> edit your profile directly in the app.</li>
    <li><strong>Erasure:</strong> <em>Settings → Privacy &amp; data → Delete my account</em>.</li>
  </ul>
  <p>
    For anything else, write to <a href="mailto:{{ $contact }}">{{ $contact }}</a>. We respond within the
    applicable statutory deadlines. If you think we handled your request badly, you can complain to Uruguay's
    Personal Data Regulatory and Control Unit (URCDP) or, if you are in the European Union, to your country's
    supervisory authority.
  </p>

  <h2 id="deletion">Deleting your account</h2>
  <p>
    You can delete your account from inside the app, under <em>Settings → Privacy &amp; data</em>. No email
    and no web form required.
  </p>
  <div class="note">
    <p>
      Your session ends and every token is revoked <strong>immediately</strong>. The irreversible erasure
      happens <strong>14 days later</strong>: signing back in within that window cancels it. After it, nothing
      can.
    </p>
  </div>
  <p>When the erasure runs:</p>
  <ul>
    <li>
      <strong>Deleted:</strong> your profile, your photo, your linked-account tokens, your devices and
      sessions, your notifications, your followers and follows, your reviews, your lists, your private tags,
      your reports (and any that name you), your invitations, your profile and venue claims, any posts you shared
      that never published along with their files, and your exported data archive. You are also removed from
      people search.
    </li>
    <li>
      <strong>Anonymised:</strong> whatever has become part of a venue's record. Corrections you suggested
      about a venue continue to exist for that venue, but stop being attached to your name, and any free text
      you wrote is erased.
    </li>
    <li>
      <strong>Retained:</strong> redemption, payout and ledger records, with no profile attached, because the
      law requires those books to be kept.
    </li>
  </ul>

  <h2 id="transfers">International transfers</h2>
  <p>
    Some of the providers named above operate outside Uruguay, mainly in the United States and the European
    Union. Where required, those transfers rely on standard contractual clauses or another mechanism provided
    for by the applicable rules.
  </p>

  <h2 id="children">Children</h2>
  <p>
    Reelmap is not directed at children under {{ $minimumAge }} and we do not knowingly collect data from
    anyone that age. If we find an account belonging to someone under {{ $minimumAge }}, we delete it. If you
    are a parent or guardian and
    believe a child in your care has created an account, write to
    <a href="mailto:{{ $contact }}">{{ $contact }}</a> and we will remove it.
  </p>
  <div class="note">
    <p>
      When you create an account we ask for your date of birth to check you meet
      that minimum age. <strong>That date is not stored.</strong> It is used in
      the moment to make the check and then discarded — all that is recorded is
      that a check was made, and when. It is separate from the optional date of
      birth you can add to your profile, which is kept because you chose to put
      it there and can clear it whenever you like.
    </p>
  </div>

  <h2 id="security">Security</h2>
  <p>
    All traffic between the app and our servers is encrypted in transit. Passwords are stored hashed,
    two-step verification secrets are stored encrypted, private files live in non-public storage behind
    short-lived signed links, and error reports carry no personal data. No system is infallible, but should a
    breach affecting your data occur, we will notify you and the relevant authority within the deadlines the
    rules require.
  </p>

  <h2 id="changes">Changes to this policy</h2>
  <p>
    If we change something material, we update the effective date shown above and, where the change affects
    you significantly, tell you inside the app before it takes effect.
  </p>
@endsection
