@extends('legal.layout')

@section('title', 'Terms of Service')
@section('summary', 'The rules for using Reelmap: what you can do, what is not tolerated, how abuse is moderated, and how offers and payouts work.')
@section('updated-label', 'In effect since')
@section('toc-label', 'Contents')
@section('other-doc', '← Privacy Policy')
@section('footer-contact', 'Contact and moderation: {{ $contact }}')

@section('toc')
  <li><a href="#acceptance">Accepting these terms</a></li>
  <li><a href="#who">Who can use Reelmap</a></li>
  <li><a href="#account">Your account</a></li>
  <li><a href="#what-it-is">What Reelmap is, and what it is not</a></li>
  <li><a href="#your-content">The content you post</a></li>
  <li><a href="#zero-tolerance">Zero tolerance for objectionable content</a></li>
  <li><a href="#report">Reporting and blocking</a></li>
  <li><a href="#copyright">Copyright and takedowns</a></li>
  <li><a href="#offers">Offers, redemptions and payouts</a></li>
  <li><a href="#availability">Service availability</a></li>
  <li><a href="#termination">Suspension and closing your account</a></li>
  <li><a href="#warranties">Warranties and liability</a></li>
  <li><a href="#law">Governing law</a></li>
  <li><a href="#stores">App store terms</a></li>
  <li><a href="#changes">Changes to these terms</a></li>
@endsection

@section('body')
  <h2 id="acceptance">Accepting these terms</h2>
  <p>
    These terms are the agreement between you and <strong>{{ $controller }}</strong>, based in
    {{ $domicile }}, who builds and operates Reelmap. By creating an account or using the app, you accept what is
    written here. If you disagree with any part of it, do not use the service.
  </p>
  <p>
    How your personal data is handled is governed by the
    <a href="{{ url('/privacy/en') }}">privacy policy</a>, which forms part of these terms.
  </p>

  <h2 id="who">Who can use Reelmap</h2>
  <p>
    You must be at least <strong>{{ $minimumAge }} years old</strong> to create an account, and we check this
    when you register. Where the law of your country sets a higher minimum — for using a service like this, or
    for consenting to the processing of your personal data — you must meet that higher age instead.
    <strong>We apply one global minimum and do not check which country you are in</strong>, so meeting any
    higher local requirement is on you. By registering you confirm that you do.
  </p>

  <h2 id="account">Your account</h2>
  <p>
    You are responsible for keeping your password safe and for the activity that happens on your account. The
    details you enter must be truthful: do not impersonate another person, a venue or a brand. You can turn on
    two-step verification under <em>Settings → Security</em>, and we recommend it.
  </p>
  <p>
    One account per person. If you claim a creator profile or a venue listing, you must be authorised to do
    so; false claims are grounds for immediate closure.
  </p>

  <h2 id="what-it-is">What Reelmap is, and what it is not</h2>
  <p>
    Reelmap takes public social media posts that you share, works out which venue they are about, and puts it
    on a map. Two things follow from that, and they are worth saying plainly:
  </p>
  <ul>
    <li>
      <strong>Place data is community-sourced and machine-generated.</strong> It comes from analysing posts
      and from external sources such as Google Places. It can be <strong>wrong, out of date or
      incomplete</strong>. Confirm opening hours, prices and addresses with the venue before you go. If you
      spot an error, you can suggest a correction from the place's page.
    </li>
    <li>
      <strong>We are not the author of the posts.</strong> Original posts belong to the people who made them
      and the platforms they live on; Reelmap links to or embeds them, it does not re-host them. A venue
      appearing on the map does not mean that creator or that venue is affiliated with us or endorses us.
    </li>
  </ul>
  <p>Only share posts that are public, or that you have the right to share.</p>

  <h2 id="your-content">The content you post</h2>
  <p>
    Your reviews, lists, suggested corrections and other contributions remain yours. By posting them on
    Reelmap you grant us a non-exclusive, worldwide, royalty-free licence to host, display and distribute them
    within the service, for the sole purpose of making it work. You can withdraw your content by deleting it
    or closing your account.
  </p>
  <p>
    With one exception, stated up front: <strong>corrections you suggest about a venue</strong> become part of
    that venue's record. If you delete your account those corrections remain, but they
    <strong>stop being attached to your name</strong> and any free text you wrote is erased.
  </p>

  <h2 id="zero-tolerance">Zero tolerance for objectionable content</h2>
  <div class="note">
    <p>
      <strong>There is no tolerance whatsoever for objectionable content or abusive users.</strong> We review
      every report we receive and, where warranted, <strong>remove the content and eject the account
      responsible within 24 hours</strong> of the report.
    </p>
  </div>
  <p>Specifically, you may not post or send:</p>
  <ul>
    <li>Harassment, threats, intimidation, or incitement to hatred or violence against a person or group.</li>
    <li>Sexually explicit content, or any content that sexualises minors.</li>
    <li>Content promoting illegal activity, self-harm or drug use.</li>
    <li>Defamation, other people's private information, or impersonation.</li>
    <li>Spam, undisclosed promotion, fake reviews, or manipulation of a venue's reputation.</li>
    <li>Material you do not hold the rights to, or that infringes someone else's.</li>
    <li>Attempts to breach, overload or bulk-extract data from the service, and the use of automated systems
      to access it without our permission.</li>
  </ul>
  <p>
    These rules cover everything you post: reviews, lists, username, photo, bio, suggested corrections and
    free-text notes.
  </p>

  <h2 id="report">Reporting and blocking</h2>
  <p>You have two tools, and they deliberately do different things:</p>
  <ul>
    <li>
      <strong>Reporting</strong> asks a moderator to look at something. You can report a profile, a place or
      a review from its own screen.
    </li>
    <li>
      <strong>Blocking</strong> takes effect immediately and waits for nobody: you stop seeing each other,
      follows are severed in both directions, and that account disappears from your experience. You can undo
      it under <em>Settings → Blocked accounts</em>.
    </li>
  </ul>
  <p>
    For urgent reports, or anything that does not fit a screen, write to
    <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
  </p>

  <h2 id="copyright">Copyright and takedowns</h2>
  <p>
    We respect copyright. Original videos downloaded for analysis are deleted within 72 hours and are never
    re-hosted: what you see is a link to, or an embed of, the original post.
  </p>
  <p>
    If you are a rights holder and believe something on Reelmap infringes your rights, write to
    <a href="mailto:{{ $contact }}">{{ $contact }}</a> telling us what the content is and where, what
    right you hold in it, how to contact you, and a good-faith statement. We remove infringing material and
    terminate repeat infringers. If you believe we removed something of yours by mistake, reply to that same
    address and we will review it.
  </p>

  <h2 id="offers">Offers, redemptions and payouts</h2>
  <p>Some venues publish offers you can redeem on site. About those:</p>
  <ul>
    <li>
      <strong>The offer is an agreement between you and the venue</strong>, not with Reelmap. The venue sets
      the conditions, the validity period and the stock, and is responsible for honouring it. We issue and
      verify the redemption code.
    </li>
    <li>
      Redemptions are personal and non-transferable. Any attempt to duplicate, forge or resell a code voids
      the redemption and may end in the account being closed.
    </li>
    <li>
      If you get paid as a creator, payouts are processed through <strong>Stripe</strong> and are also subject
      to Stripe's terms. To be paid you must complete the identity verification Stripe requires. Balances and
      movements appear in your in-app wallet.
    </li>
  </ul>

  <h2 id="availability">Service availability</h2>
  <p>
    We do our best to keep Reelmap running, but we do not guarantee uninterrupted availability. We may change,
    suspend or discontinue features. If we are going to discontinue the service entirely, we will give you
    reasonable notice so you can download a copy of your data.
  </p>

  <h2 id="termination">Suspension and closing your account</h2>
  <p>
    We may suspend or close an account that breaches these terms, in particular the
    <a href="#zero-tolerance">zero tolerance</a> section, and may do so without prior notice where the breach
    is serious or others are at risk. You can close your account whenever you like under
    <em>Settings → Privacy &amp; data</em>; what happens to your data is set out in the
    <a href="{{ url('/privacy/en') }}">privacy policy</a>.
  </p>

  <h2 id="warranties">Warranties and liability</h2>
  <p>
    Reelmap is provided "as is". To the extent the law allows, we do not warrant that information about a
    place is accurate, current or complete, nor that the service will be error-free, and we are not liable for
    indirect or consequential loss arising from your use of the service, for content posted by other users, or
    for what happens during your visit to a venue.
  </p>
  <p>
    None of this limits liabilities that cannot be excluded by law, nor any rights you have as a consumer.
  </p>

  <h2 id="law">Governing law</h2>
  <p>
    These terms are governed by the law of the <strong>Eastern Republic of Uruguay</strong>, and any dispute
    is submitted to the courts of Montevideo. If you live elsewhere, you keep the protection of the mandatory
    consumer-protection rules of your country of residence.
  </p>

  <h2 id="stores">App store terms</h2>
  <p>
    When you download Reelmap from the App Store, these terms are concluded between you and us only, and
    <strong>not with Apple</strong>. Apple is not responsible for the app or its content and has no obligation
    whatsoever to furnish you with maintenance or support. If the app fails to conform to any applicable
    warranty, you may notify Apple and Apple will refund the purchase price, if any; to the maximum extent
    permitted by law, Apple has no other warranty obligation. Any claim relating to the app — including
    product liability, regulatory compliance and intellectual property claims — is our responsibility and not
    Apple's. Apple and its subsidiaries are third-party beneficiaries of these terms and may enforce them
    against you.
  </p>
  <p>
    Using the app also means complying with the terms of the store you downloaded it from.
  </p>

  <h2 id="changes">Changes to these terms</h2>
  <p>
    If we change something material, we update the effective date shown above and tell you inside the app
    before it takes effect. Continuing to use Reelmap after that date means accepting the new version.
  </p>
  <p class="contact">
    Any questions about these terms: <a href="mailto:{{ $contact }}">{{ $contact }}</a>.
  </p>
@endsection
