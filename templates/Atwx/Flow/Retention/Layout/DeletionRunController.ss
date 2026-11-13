<%-- Deletion runs: rules with due records, then the log of past runs. --%>
<div class="fdk-manager">
    <div class="fdk-page-header">
        <h1 class="fdk-page-title">$Title</h1>
    </div>
    <% include Atwx\\SilverstripeFrontdeskKit\\Includes\\FlashMessage %>

    <% if $RetentionRules %>
    <div class="grid gap-4 md:grid-cols-2 mb-8">
        <% loop $RetentionRules %>
        <div class="card bg-base-100 shadow-sm">
            <div class="card-body">
                <h2 class="card-title text-lg">$Title</h2>
                <% if $Description %><p class="text-sm opacity-70">$Description</p><% end_if %>
                <p>
                    <strong>$Due</strong> <%t Atwx\Flow\Retention\DeletionRunController.DUE 'due (dated before {date})' date=$Cutoff.Nice %>
                    <% if $Automatic %><span class="badge badge-sm badge-ghost ms-1"><%t Atwx\Flow\Retention\DeletionRunController.AUTOMATIC 'daily' %></span><% end_if %>
                </p>
                <% if $LastRun %>
                <p class="text-sm opacity-70"><%t Atwx\Flow\Retention\DeletionRunController.LAST_RUN 'Last run' %>: $LastRun.Created.Nice, $LastRun.Deleted <%t Atwx\Flow\Retention\DeletionRunController.DELETED 'deleted' %></p>
                <% end_if %>
                <div class="card-actions justify-end">
                    <a href="$Link" class="btn btn-sm<% if $Due %> btn-primary<% end_if %>"><%t Atwx\Flow\Retention\DeletionRunController.PREVIEW 'Preview' %></a>
                </div>
            </div>
        </div>
        <% end_loop %>
    </div>
    <% else %>
    <p class="mb-8 opacity-70"><%t Atwx\Flow\Retention\DeletionRunController.NO_RULES 'No rules configured.' %></p>
    <% end_if %>

    <h2 class="text-lg font-semibold mb-2"><%t Atwx\Flow\Retention\DeletionRunController.LOG 'Log' %></h2>
    <div id="fdk-list-region">
        <% include Atwx\\SilverstripeFrontdeskKit\\Includes\\ListTable %>
    </div>
</div>
