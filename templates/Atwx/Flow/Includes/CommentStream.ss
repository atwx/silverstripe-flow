<%-- Comments of a record as a stream (CommentSubController with display: stream):
     area "Open", new comment, filters (types, references, done), entries. --%>
<% if $Top.ShowOpen && $Top.OpenItems %>
<section class="fdk-comment-open mb-4" aria-labelledby="fdk-comment-open-title-$Top.ParentID">
    <h3 id="fdk-comment-open-title-$Top.ParentID" class="font-semibold mb-2"><%t Atwx\Flow\Controller\CommentSubController.OPEN 'Open' %> ($Top.OpenItems.Count)</h3>
    <ul class="flex flex-col gap-1">
        <% loop $Top.OpenItems %>
        <li class="fdk-comment-open-item flex items-start gap-2">
            <% if $Top.canEdit %>
            <input type="checkbox" class="checkbox checkbox-sm mt-0.5"
                   aria-label="<%t Atwx\Flow\Controller\CommentSubController.MARK_DONE 'Mark as done' %>: $OpenLine.ATT"
                   hx-post="$Top.ToggleDoneLink($ID)" hx-target="$Top.HtmxTarget" hx-swap="innerHTML"
                   hx-vals='{"SecurityID": "$Top.SecurityTokenValue"}'>
            <% end_if %>
            <span class="text-sm flex-1 min-w-0<% if $isOverdue %> text-error<% end_if %>">$OpenLine</span>
            <% if $Top.canEdit %>
            <button type="button" class="btn btn-ghost btn-xs" title="<%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.ACTION_EDIT 'Edit' %>"
                    hx-get="$Top.EditLinkFor($ID)" hx-target="#fdk-modal-content" hx-swap="innerHTML" hx-indicator="#fdk-modal-spinner"
                    onclick="document.getElementById('fdk-modal').showModal()"><i data-lucide="pencil" class="size-3.5"></i></button>
            <% end_if %>
        </li>
        <% end_loop %>
    </ul>
</section>
<% end_if %>
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

<% if $Top.TypeChips || $Top.ReferenceFilter || $Top.ShowDoneToggle %>
<div class="fdk-comment-filters flex flex-wrap items-center gap-2 mb-3">
    <% loop $Top.TypeChips %>
    <a href="$Link" hx-get="$Link" hx-target="$Top.HtmxTarget" hx-swap="innerHTML"
       class="btn btn-sm rounded-full<% if $Active %> btn-neutral<% else %> btn-ghost bg-base-200<% end_if %>"<% if $Active %> aria-current="true"<% end_if %>><% if $Icon %><i data-lucide="$Icon" class="size-4" aria-hidden="true"></i><% end_if %>$Title</a>
    <% end_loop %>
    <% if $Top.ReferenceFilter %>
    <select class="select select-sm w-auto" name="ref" aria-label="$Top.ReferencesTitle.ATT"
            onchange="htmx.ajax('GET', this.value, {target: '$Top.HtmxTarget', swap: 'innerHTML'})">
        <% loop $Top.ReferenceFilter %>
        <option value="$Link.ATT"<% if $Selected %> selected<% end_if %>>$Title</option>
        <% end_loop %>
    </select>
    <% end_if %>
    <% if $Top.ShowDoneToggle %>
    <a href="$Top.DoneToggleLink" hx-get="$Top.DoneToggleLink" hx-target="$Top.HtmxTarget" hx-swap="innerHTML"
       class="link link-hover text-sm ms-auto"><% if $Top.showsDone %><%t Atwx\Flow\Controller\CommentSubController.HIDE_DONE 'Hide done' %><% else %><%t Atwx\Flow\Controller\CommentSubController.SHOW_DONE 'Show done' %><% end_if %></a>
    <% end_if %>
</div>
<% end_if %>

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
