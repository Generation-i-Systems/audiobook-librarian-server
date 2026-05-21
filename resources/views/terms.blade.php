@extends('layouts.app')

@section('content')
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-8">
            <h1 class="mb-4">Terms and Conditions</h1>
            <p class="text-muted">Last updated: May 6, 2025</p>

            <p>These Terms and Conditions ("Terms") govern your use of Audiobook Librarian ("the Service"). By
                creating an account or using the Service, you agree to these Terms. If you do not agree, do not
                use the Service.</p>

            <h2 class="h4 mt-5 mb-3">1. Description of Service</h2>
            <p>Audiobook Librarian provides tools for managing, organizing, and listening to audiobooks stored in
                a personal or shared library. The Service synchronizes your library and listening progress across
                your devices. It does not sell or distribute audiobooks; all content in your library is provided
                by you, your library administrator, or public-domain sources such as LibriVox.</p>

            <h2 class="h4 mt-5 mb-3">2. Accounts</h2>
            <p>You must create an account to use the Service. You are responsible for:</p>
            <ul>
                <li>Keeping your login credentials confidential</li>
                <li>All activity that occurs under your account</li>
                <li>Notifying us immediately of any unauthorized access</li>
            </ul>
            <p>You must provide an accurate email address and keep it up to date. Accounts are for individual use
                unless your library administrator has explicitly set up shared or family access.</p>

            <h2 class="h4 mt-5 mb-3">3. Your Content and Your Library</h2>

            <h3 class="h5 mt-4 mb-2">Personal and Shared Libraries</h3>
            <p>You may use Audiobook Librarian to manage audiobooks that you own, have purchased, or have otherwise
                lawfully obtained the right to use. You may also be granted access to a library managed by an
                associate (such as a family member or trusted friend), provided that such shared access is permitted
                under the terms applicable to the content in that library and under applicable law.</p>
            <p>You represent and warrant that:</p>
            <ul>
                <li>You have the legal right to access and use all content in your library through this Service</li>
                <li>Your use of the Service does not infringe the intellectual property rights of any third party</li>
                <li>You will not use the Service to distribute copyrighted content to unauthorized parties</li>
            </ul>

            <h3 class="h5 mt-4 mb-2">LibriVox and Public Domain Content</h3>
            <p>The Service supports libraries that include recordings from
                <a href="https://librivox.org" target="_blank" rel="noopener noreferrer">LibriVox</a> and other
                public domain audiobook sources. LibriVox recordings are released into the public domain worldwide
                and may be freely used. We are not affiliated with LibriVox. It is your responsibility to verify
                the public domain status of any content you add to your library.</p>

            <h3 class="h5 mt-4 mb-2">Library Administrators</h3>
            <p>If you operate a library that is shared with other users of the Service, you ("Library
                Administrator") take on additional responsibilities:</p>
            <ul>
                <li>You represent that all content in the shared library is lawfully available to the users you
                    have authorized</li>
                <li>You are responsible for managing user access and revoking it when appropriate</li>
                <li>You agree not to use the Service to facilitate copyright infringement</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">4. Prohibited Uses</h2>
            <p>You agree not to:</p>
            <ul>
                <li>Use the Service to store or share content you do not have the right to use</li>
                <li>Attempt to circumvent authentication, access controls, or rate limits</li>
                <li>Interfere with or disrupt the Service or its infrastructure</li>
                <li>Use the Service in violation of any applicable law or regulation</li>
                <li>Reverse-engineer, decompile, or extract the source code of the Service (beyond what is
                    permitted by applicable law)</li>
            </ul>

            <h2 class="h4 mt-5 mb-3">5. Intellectual Property</h2>
            <p>The Service, including its software, design, and documentation, is owned by Audiobook Librarian
                and protected by applicable intellectual property laws. These Terms do not transfer any ownership
                rights to you.</p>
            <p>You retain ownership of any data you provide to the Service (your library metadata, notes, and
                bookmarks). You grant us a limited license to store and process that data solely to provide the
                Service to you.</p>

            <h2 class="h4 mt-5 mb-3">6. Disclaimer of Warranties</h2>
            <p>The Service is provided "as is" and "as available" without warranties of any kind, express or
                implied. We do not warrant that the Service will be uninterrupted, error-free, or free of harmful
                components. Your use of the Service is at your own risk.</p>

            <h2 class="h4 mt-5 mb-3">7. Limitation of Liability</h2>
            <p>To the fullest extent permitted by applicable law, Audiobook Librarian and its operators shall not
                be liable for any indirect, incidental, special, consequential, or punitive damages arising out of
                or related to your use of the Service, even if advised of the possibility of such damages. Our
                total liability to you for any claim arising out of or related to these Terms or the Service shall
                not exceed the amount you paid us in the twelve months preceding the claim.</p>

            <h2 class="h4 mt-5 mb-3">8. Indemnification</h2>
            <p>You agree to indemnify and hold harmless Audiobook Librarian and its operators from any claims,
                damages, losses, or expenses (including reasonable legal fees) arising out of your use of the
                Service, your violation of these Terms, or your infringement of any third-party rights.</p>

            <h2 class="h4 mt-5 mb-3">9. Termination</h2>
            <p>We may suspend or terminate your account at any time if you violate these Terms or if we reasonably
                believe your use poses a risk to the Service or other users. You may delete your account at any
                time. Upon termination, your right to use the Service ceases and we will delete your data as
                described in our Privacy Policy.</p>

            <h2 class="h4 mt-5 mb-3">10. Changes to These Terms</h2>
            <p>We may update these Terms from time to time. We will notify you of material changes by posting a
                notice in the app or by email. Your continued use of the Service after changes take effect
                constitutes your acceptance of the updated Terms.</p>

            <h2 class="h4 mt-5 mb-3">11. Governing Law</h2>
            <p>These Terms are governed by the laws of the United States and the State of Idaho, without
                regard to conflict of law principles. Any disputes shall be resolved in the federal or state courts
                located in Idaho, and you consent to the personal jurisdiction of such courts.</p>

            <h2 class="h4 mt-5 mb-3">12. Contact</h2>
            <p>If you have questions about these Terms, please contact us at:</p>
            <p><strong>Audiobook Librarian</strong><br>
                <a href="mailto:support@ablibrarian.com">support@ablibrarian.com</a></p>

            <hr class="mt-5">
            <p class="text-muted small">By using Audiobook Librarian, you acknowledge that you have read and
                understood these Terms and agree to be bound by them.</p>
        </div>
    </div>
</div>
@endsection
