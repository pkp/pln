{**
 * templates/statusGridFilter.tpl
 *
 * Filter form for the PLN deposits grid.
 *}
{assign var="formId" value="plnDepositsFilter-"|concat:$filterData.gridId}
<script>
	$('#{$formId}').pkpHandler('$.pkp.controllers.form.ClientFormHandler', {ldelim}
		trackFormChanges: false
	{rdelim});
</script>
<form class="pkp_form filter" id="{$formId}" action="{url op="fetchGrid"}" method="post">
	{csrf}
	{fbvFormArea id="plnDepositsSearchFormArea"|concat:$filterData.gridId}
		{fbvFormSection}
			{fbvElement type="search" name="search" id="search" value=$filterSelectionData.search label="common.search" size=$fbvStyles.size.MEDIUM inline="true"}
			{fbvElement type="select" name="status" id="status" from=$filterData.statuses selected=$filterSelectionData.status label="common.status" size=$fbvStyles.size.SMALL translate=false inline="true"}
		{/fbvFormSection}
		{fbvFormButtons hideCancel=true submitText="common.search"}
	{/fbvFormArea}
</form>
