<%-- Follow-ups, to-dos and appointments as a stream, each with its record. --%>
<p class="fdk-count">
    $Items.TotalItems <%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.LABEL_RECORDS 'records' %>
    <% if $FilterIsActive %>– <a href="$Link" class="link link-primary"><%t Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController.ACTION_CLEAR_FILTERS 'Clear filters' %></a><% end_if %>
</p>
<% if $Top.BulkActions.Count %>
    <% include Atwx\\Flow\\Includes\\CommentStreamEntries BulkForm='fdk-bulk-form', WithRecordLink=1 %>
<% else %>
    <% include Atwx\\Flow\\Includes\\CommentStreamEntries WithRecordLink=1 %>
<% end_if %>
<% include Atwx\\SilverstripeFrontdeskKit\\Includes\\Pagination ItemList=$Items %>
