<%-- A page of the work list (config pages): "By member" and columns side by side
     (one below the other on phones), each entry with tick, edit, delete and the record. --%>
<div class="fdk-manager fdk-followup-page">
    <div class="fdk-page-header flex-wrap gap-3">
        <h1 class="fdk-page-title">$Title</h1>
        <% if $MemberChoice %>
        <form method="get" action="$PageLink" class="flex items-center gap-2">
            <label for="fdk-followup-member" class="text-sm"><%t Atwx\Flow\Controller\FollowUpController.BY_MEMBER 'By member' %></label>
            <select id="fdk-followup-member" name="member" class="select select-sm w-auto" onchange="this.form.submit()">
                <% loop $MemberChoice.Options %><option value="$ID"<% if $Selected %> selected<% end_if %>>$Name</option><% end_loop %>
            </select>
            <noscript><button type="submit" class="btn btn-sm">OK</button></noscript>
        </form>
        <% end_if %>
    </div>

    <% include Atwx\\SilverstripeFrontdeskKit\\Includes\\FlashMessage %>

    <div class="fdk-followup-columns grid gap-6 items-start<% if $ColumnCount > 1 %> lg:grid-cols-2<% end_if %><% if $ColumnCount > 2 %> xl:grid-cols-3<% end_if %>">
        <% loop $Columns %>
        <section class="fdk-followup-column" aria-labelledby="fdk-followup-$Key">
            <h2 id="fdk-followup-$Key" class="text-lg font-semibold mb-2">$Title <span class="text-base-content/50 font-normal">($Count)</span></h2>
            <div class="fdk-comment-stream flex flex-col gap-2">
                <% loop $Entries %><% include Atwx\\Flow\\Includes\\FollowUpPageEntry %><% end_loop %>
                <% if not $Entries %>
                <p class="text-base-content/50 py-2"><%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.NO_RECORDS 'No records found.' %></p>
                <% end_if %>
                <% if $More %>
                <p class="text-sm text-base-content/60"><%t Atwx\Flow\Controller\FollowUpController.MORE_ENTRIES '{count} more' count=$More %></p>
                <% end_if %>
            </div>
        </section>
        <% end_loop %>
    </div>
</div>

<!-- Edit modal: content loaded via HTMX -->
<dialog id="fdk-modal" class="modal">
    <div class="modal-box w-11/12 max-w-3xl">
        <span id="fdk-modal-spinner" class="htmx-indicator loading loading-spinner loading-md"></span>
        <div id="fdk-modal-content"></div>
    </div>
    <form method="dialog" class="modal-backdrop"><button>close</button></form>
</dialog>
