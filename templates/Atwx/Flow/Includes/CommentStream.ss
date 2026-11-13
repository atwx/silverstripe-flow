<%-- Comments of a record as a stream (CommentSubController with display: stream). --%>
<div class="flex items-center justify-between mb-2 gap-2 flex-wrap">
    <p class="fdk-count">$Items.TotalItems <%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.LABEL_RECORDS 'records' %></p>
    <div class="flex items-center gap-2">
        <% if $Top.canEdit && $Items.TotalItems > 1 %>
        <form id="$Top.StreamBulkForm" hx-post="$Top.Link('deleteselected')" hx-target="$Top.HtmxTarget" hx-swap="innerHTML"
              hx-confirm="<%t Atwx\Flow\Controller\CommentSubController.CONFIRM_DELETE_SELECTED 'Delete the selected comments?' %>">
            <input type="hidden" name="SecurityID" value="$Top.SecurityTokenValue">
            <button type="submit" class="btn btn-ghost btn-sm"><%t Atwx\Flow\Controller\CommentSubController.DELETE_SELECTED 'Delete selected' %></button>
        </form>
        <% end_if %>
        <% if $Top.canAdd %>
        <button type="button"
                class="btn btn-primary btn-sm"
                hx-get="$Top.Link('add')"
                hx-target="#fdk-modal-content"
                hx-swap="innerHTML"
                hx-indicator="#fdk-modal-spinner"
                onclick="document.getElementById('fdk-modal').showModal()">
            <%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.ACTION_NEW 'New' %>
        </button>
        <% end_if %>
    </div>
</div>
<% if $Message %><div role="status" class="alert alert-success mb-2">$Message</div><% end_if %>

<% if $Top.canEdit && $Items.TotalItems > 1 %>
    <% include Atwx\\Flow\\Includes\\CommentStreamEntries BulkForm=$Top.StreamBulkForm %>
<% else %>
    <% include Atwx\\Flow\\Includes\\CommentStreamEntries %>
<% end_if %>

<% if $Items.MoreThanOnePage %>
<div class="fdk-pagination flex justify-center mt-4">
    <div class="join">
        <% if $Items.NotFirstPage %>
            <a class="join-item btn btn-sm" href="$Items.PrevLink" hx-get="$Items.PrevLink" hx-target="$Top.HtmxTarget" hx-swap="innerHTML">«</a>
        <% end_if %>
        <% loop $Items.PaginationSummary %>
            <% if $CurrentBool %>
                <button class="join-item btn btn-sm btn-active">$PageNum</button>
            <% else_if $Link %>
                <a class="join-item btn btn-sm" href="$Link" hx-get="$Link" hx-target="$Top.HtmxTarget" hx-swap="innerHTML">$PageNum</a>
            <% else %>
                <button class="join-item btn btn-sm btn-disabled">…</button>
            <% end_if %>
        <% end_loop %>
        <% if $Items.NotLastPage %>
            <a class="join-item btn btn-sm" href="$Items.NextLink" hx-get="$Items.NextLink" hx-target="$Top.HtmxTarget" hx-swap="innerHTML">»</a>
        <% end_if %>
    </div>
</div>
<% end_if %>
