<%-- Comments as a stream: meta line, date, kind and responsible member, text;
     highlighted: open appointments and important ones. $BulkForm: id of the
     form the checkboxes belong to (empty: no checkboxes). --%>
<div class="fdk-comment-stream flex flex-col gap-2">
    <% loop $Top.ItemRows %>
    <article id="fdk-row-$ID" class="fdk-comment card card-sm bg-base-100 shadow-sm<% if $Record.StreamHighlight %> fdk-comment-highlight border-l-4 border-primary<% end_if %>">
        <div class="card-body flex-row gap-3 items-start">
            <% if $Up.BulkForm %>
            <input type="checkbox" class="checkbox checkbox-sm mt-1" name="ids[]" value="$ID" form="$Up.BulkForm"
                   aria-label="<%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.LABEL_SELECT_RECORD 'Select record' %> $ID">
            <% end_if %>
            <div class="flex-1 min-w-0">
                <% if $Top.ShowTypes && $Record.TypeLabel %>
                <span class="fdk-comment-type badge badge-sm badge-ghost gap-1 mb-1"><% if $Record.TypeIcon %><i data-lucide="$Record.TypeIcon" class="size-3" aria-hidden="true"></i><% end_if %>$Record.TypeLabel</span>
                <% end_if %>
                <p class="text-xs text-base-content/60"><% if $Record.Important %><span class="badge badge-xs badge-warning me-1"><%t Atwx\Flow\Model\Comment.IMPORTANT_PREFIX 'important' %></span><% end_if %><% if $Top.ShowTypes %>$Record.StreamMetaWithoutType<% else %>$Record.StreamMeta<% end_if %><% if $Record.EditedByNote %> <span class="italic">· $Record.EditedByNote</span><% end_if %></p>
                <% if $Record.StreamDue %><p class="text-sm font-semibold">$Record.StreamDue</p><% end_if %>
                <p class="whitespace-normal break-words"><% if $Record.StreamLead %><strong>$Record.StreamLead</strong> <% end_if %>$Record.TextHtml</p>
                <% if $Top.ShowReferences && $Record.ReferenceTitles %>
                <p class="fdk-comment-references flex flex-wrap gap-1 mt-1"><% loop $Record.ReferenceTitles %><span class="badge badge-sm badge-outline">$Title</span><% end_loop %></p>
                <% end_if %>
                <% if $Up.WithRecordLink && $Record.RecordLink %>
                <p class="text-sm mt-1"><a href="$Record.RecordLink" class="link link-primary">$Record.RecordTitle</a></p>
                <% end_if %>
            </div>
            <div class="shrink-0">
                <% include Atwx\\SilverstripeFrontdeskKit\\Includes\\RowActions Actions=$RowActions %>
            </div>
        </div>
    </article>
    <% end_loop %>
    <% if not $Top.ItemRows %>
    <p class="text-base-content/50 py-4"><%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.NO_RECORDS 'No records found.' %></p>
    <% end_if %>
</div>
