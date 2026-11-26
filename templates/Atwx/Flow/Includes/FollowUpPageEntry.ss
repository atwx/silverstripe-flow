<%-- An entry on a page of the work list: tick off, date in bold, meta line, text, record;
     edit, delete and (to-do basket) take over. The actions answer htmx requests in place. --%>
<% with $Comment %>
<article id="fdk-followup-$ID" class="fdk-comment card card-sm bg-base-100 shadow-sm<% if $isOverdue %> border-l-4 border-error<% else_if $StreamHighlight %> fdk-comment-highlight border-l-4 border-primary<% end_if %>">
    <div class="card-body flex-row gap-3 items-start">
        <% if $Up.CanEdit %>
        <input type="checkbox" class="checkbox checkbox-sm mt-1"
               aria-label="<%t Atwx\Flow\Controller\CommentSubController.MARK_DONE 'Mark as done' %>: $Text.LimitCharacters(60).ATT"
               hx-post="$Up.DoneLink" hx-vals='{"SecurityID": "$Up.Token"}'
               hx-target="#fdk-followup-$ID" hx-swap="outerHTML">
        <% end_if %>
        <div class="flex-1 min-w-0">
            <% if $DueLabel %><p class="font-semibold<% if $isOverdue %> text-error<% end_if %>">$DueLabel</p><% end_if %>
            <p class="text-xs text-base-content/60">$StreamMeta</p>
            <p class="whitespace-normal break-words"><% if $StreamLead %><strong>$StreamLead</strong> <% end_if %>$TextHtml</p>
            <% if $RecordLink %><p class="text-sm mt-1"><a href="$RecordLink" class="link link-primary">$RecordTitle</a></p><% end_if %>
        </div>
        <div class="shrink-0 flex items-center gap-0.5">
            <% if $Up.TakeLink %>
            <button type="button" class="btn btn-ghost btn-xs" hx-post="$Up.TakeLink" hx-vals='{"SecurityID": "$Up.Token"}'
                    title="<%t Atwx\Flow\Controller\FollowUpController.TAKE 'Take over' %>"><%t Atwx\Flow\Controller\FollowUpController.TAKE 'Take over' %></button>
            <% end_if %>
            <% if $Up.EditLink %>
            <button type="button" class="btn btn-ghost btn-xs" title="<%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.ACTION_EDIT 'Edit' %>"
                    hx-get="$Up.EditLink" hx-target="#fdk-modal-content" hx-swap="innerHTML" hx-indicator="#fdk-modal-spinner"
                    onclick="document.getElementById('fdk-modal').showModal()"><i data-lucide="pencil" class="size-4"></i></button>
            <% end_if %>
            <% if $Up.CanDelete %>
            <button type="button" class="btn btn-ghost btn-xs" title="<%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.ACTION_DELETE 'Delete' %>"
                    hx-post="$Up.RemoveLink" hx-vals='{"SecurityID": "$Up.Token"}' hx-target="#fdk-followup-$ID" hx-swap="outerHTML"
                    hx-confirm="<%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.CONFIRM_DELETE 'Are you sure you want to delete this record?' %>"><i data-lucide="trash-2" class="size-4"></i></button>
            <% end_if %>
        </div>
    </div>
</article>
<% end_with %>
