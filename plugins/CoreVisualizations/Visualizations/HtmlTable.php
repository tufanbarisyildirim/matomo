<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CoreVisualizations\Visualizations;

use Piwik\API\Request as ApiRequest;
use Piwik\Columns\Dimension;
use Piwik\Common;
use Piwik\Metrics;
use Piwik\DataTable;
use Piwik\Period;
use Piwik\Plugin\Visualization;

/**
 * DataTable visualization that shows DataTable data in an HTML table.
 *
 * @property HtmlTable\Config $config
 */
class HtmlTable extends Visualization
{
    const ID = 'table';
    const TEMPLATE_FILE     = "@CoreVisualizations/_dataTableViz_htmlTable.twig";
    const FOOTER_ICON       = 'icon-table';
    const FOOTER_ICON_TITLE = 'General_DisplaySimpleTable';

    public static function getDefaultConfig()
    {
        return new HtmlTable\Config();
    }

    public static function getDefaultRequestConfig()
    {
        return new HtmlTable\RequestConfig();
    }

    public function beforeRender()
    {
        if ($this->requestConfig->idSubtable
            && $this->config->show_embedded_subtable) {

            $this->config->show_visualization_only = true;
        }

        if ($this->requestConfig->idSubtable) {
            $this->config->show_totals_row = false;
        }

        foreach (Metrics::getMetricIdsToProcessReportTotal() as $metricId) {
            $this->config->report_ratio_columns[] = Metrics::getReadableColumnName($metricId);
        }
        if (!empty($this->report)) {
            foreach ($this->report->getMetricNamesToProcessReportTotals() as $metricName) {
                $this->config->report_ratio_columns[] = $metricName;
            }
        }

        // we do not want to get a datatable\map
        $period = Common::getRequestVar('period', 'day', 'string');
        if (Period\Range::parseDateRange($period)) {
            $period = 'range';
        }

        if ($this->dataTable->getRowsCount()) {
            $request = new ApiRequest(array(
                'method' => 'API.get',
                'module' => 'API',
                'action' => 'get',
                'format' => 'original',
                'filter_limit'  => '-1',
                'disable_generic_filters' => 1,
                'expanded'      => 0,
                'flat'          => 0,
                'filter_offset' => 0,
                'period'        => $period,
                'showColumns'   => implode(',', $this->config->columns_to_display),
                'columns'       => implode(',', $this->config->columns_to_display),
                'pivotBy'       => ''
            ));

            $dataTable = $request->process();
            $this->assignTemplateVar('siteSummary', $dataTable);
        }

        if ($this->isPivoted()) {
            $this->config->columns_to_display = $this->dataTable->getColumns();
        }

        // Note: This needs to be done right before rendering, as otherwise some plugins might change the columns to display again
        if ($this->isFlattened() && $this->config->show_dimensions) {
            $dimensions = $this->dataTable->getMetadata('dimensions');

            $hasMultipleDimensions = is_array($dimensions) && count($dimensions) > 1;
            $this->assignTemplateVar('hasMultipleDimensions', $hasMultipleDimensions);

            if (is_array($dimensions) && count($dimensions) > 1) {

                foreach (Dimension::getAllDimensions() as $dimension) {
                    $dimensionId = str_replace('.', '_', $dimension->getId());
                    $dimensionName = $dimension->getName();

                    if (!empty($dimensionId) && !empty($dimensionName) && in_array($dimensionId, $dimensions)) {
                        $this->config->translations[$dimensionId] = $dimensionName;
                    }
                }

                $this->dataTable->filter(function($dataTable) use ($dimensions) {
                    /** @var DataTable $dataTable */
                    $rows = $dataTable->getRows();
                    foreach ($rows as $row) {
                        foreach ($dimensions as $dimension) {
                            $row->setColumn($dimension, $row->getMetadata($dimension));
                        }
                    }
                });

                # replace original label column with first dimension
                $firstDimension = array_shift($dimensions);
                $this->dataTable->filter('ColumnDelete', array('label'));
                $this->dataTable->filter('ReplaceColumnNames', array(array($firstDimension => 'label')));

                $properties = $this->config;

                $this->dataTable->filter(function (DataTable $dataTable) use ($properties, $dimensions) {
                    if (empty($properties->columns_to_display)) {
                        $columns           = $dataTable->getColumns();
                        $hasNbVisits       = in_array('nb_visits', $columns);
                        $hasNbUniqVisitors = in_array('nb_uniq_visitors', $columns);

                        $properties->setDefaultColumnsToDisplay($columns, $hasNbVisits, $hasNbUniqVisitors);
                    }

                    $label = array_search('label', $properties->columns_to_display);
                    if ($label !== false) {
                        unset($properties->columns_to_display[$label]);
                    }

                    foreach ($dimensions as $dimension) {
                        array_unshift($properties->columns_to_display, $dimension);
                    }

                    array_unshift($properties->columns_to_display, 'label');
                });
            }
        }
    }

    public function beforeGenericFiltersAreAppliedToLoadedDataTable()
    {
        if ($this->isPivoted()) {
            $this->config->columns_to_display = $this->dataTable->getColumns();

            $this->dataTable->applyQueuedFilters();
        }

        parent::beforeGenericFiltersAreAppliedToLoadedDataTable();
    }

    protected function isPivoted()
    {
        return $this->requestConfig->pivotBy || Common::getRequestVar('pivotBy', '');
    }

    protected function isFlattened()
    {
        return $this->requestConfig->flat || Common::getRequestVar('flat', '');
    }
}
