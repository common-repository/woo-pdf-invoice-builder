<?php
/**
 * @package dompdf
 * @link    http://dompdf.github.com/
 * @author  Benj Carson <benjcarson@digitaljunkies.ca>
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace rnDompdf;

use rnDompdf\Renderer\AbstractRenderer;
use rnDompdf\Renderer\Block;
use rnDompdf\Renderer\Image;
use rnDompdf\Renderer\ListBullet;
use rnDompdf\Renderer\TableCell;
use rnDompdf\Renderer\TableRowGroup;
use rnDompdf\Renderer\Text;

use rnDompdf\Frame;

/**
 * Concrete renderer
 *
 * Instantiates several specific renderers in order to render any given frame.
 *
 * @package dompdf
 */
class Renderer extends AbstractRenderer
{

    /**
     * Array of renderers for specific frame types
     *
     * @var AbstractRenderer[]
     */
    protected $_renderers;

    /**
     * Cache of the callbacks array
     *
     * @var array
     */
    private $_callbacks;

    /**
     * Advance the canvas to the next page
     */
    function new_page()
    {
        $this->_canvas->new_page();
    }

    /**
     * Render frames recursively
     *
     * @param Frame $frame the frame to render
     */
    public function render(Frame $frame)
    {
        global $_dompdf_debug;

        $this->_check_callbacks("begin_frame", $frame);

        if ($_dompdf_debug) {
            echo $frame;
            flush();
        }

        $style = $frame->get_style();

        if (in_array($style->visibility, array("hidden", "collapse"))) {
            return;
        }

        $display = $style->display;

        // Starts the CSS transformation
        if ($style->transform && is_array($style->transform)) {
            $this->_canvas->save();
            list($x, $y) = $frame->get_padding_box();
            $origin = $style->transform_origin;

            foreach ($style->transform as $transform) {
                list($function, $values) = $transform;
                if ($function === "matrix") {
                    $function = "transform";
                }

                $values = array_map("floatval", $values);
                $values[] = $x + (float)$style->length_in_pt($origin[0], (float)$style->length_in_pt($style->width));
                $values[] = $y + (float)$style->length_in_pt($origin[1], (float)$style->length_in_pt($style->height));

                call_user_func_array(array($this->_canvas, $function), $values);
            }
        }

        switch ($display) {

            case "block":
            case "list-item":
            case "inline-block":
            case "table":
            case "inline-table":
                $this->_render_frame("block", $frame);
                break;

            case "inline":
                if ($frame->is_text_node()) {
                    $this->_render_frame("text", $frame);
                } else {
                    $this->_render_frame("inline", $frame);
                }
                break;

            case "table-cell":
                $this->_render_frame("table-cell", $frame);
                break;

            case "table-row-group":
            case "table-header-group":
            case "table-footer-group":
                $this->_render_frame("table-row-group", $frame);
                break;

            case "-dompdf-list-bullet":
                $this->_render_frame("list-bullet", $frame);
                break;

            case "-dompdf-image":
                $this->_render_frame("image", $frame);
                break;

            case "none":
                $node = $frame->get_node();

                if ($node->nodeName === "script") {
                    if ($node->getAttribute("type") === "text/php" ||
                        $node->getAttribute("language") === "php"
                    ) {
                        // Evaluate embedded php scripts
                        $this->_render_frame("php", $frame);
                    } elseif ($node->getAttribute("type") === "text/javascript" ||
                        $node->getAttribute("language") === "javascript"
                    ) {
                        // Insert JavaScript
                        $this->_render_frame("javascript", $frame);
                    }
                }

                // Don't render children, so skip to next iter
                return;

            default:
                break;

        }

        // Starts the overflow: hidden box
        if ($style->overflow === "hidden") {
            list($x, $y, $w, $h) = $frame->get_padding_box();

            // get border radii
            $style = $frame->get_style();
            list($tl, $tr, $br, $bl) = $style->get_computed_border_radius($w, $h);

            //modification: I did this to make the text boxes that doesn't fully show the text by an small marging to expand
            $childrenHeight=$frame->GetChildrenHeight();

            if($h<$childrenHeight&&$h+15>$childrenHeight)
                $h=$childrenHeight;

            if ($tl + $tr + $br + $bl > 0) {
                $this->_canvas->clipping_roundrectangle($x, $y, (float)$w, (float)$h, $tl, $tr, $br, $bl);
            } else {
                $this->_canvas->clipping_rectangle($x, $y, (float)$w, (float)$h);
            }
        }

        $stack = array();

        foreach ($frame->get_children() as $child) {
            // < 0 : nagative z-index
            // = 0 : no z-index, no stacking context
            // = 1 : stacking context without z-index
            // > 1 : z-index
            $child_style = $child->get_style();
            $child_z_index = $child_style->z_index;
            $z_index = 0;

            if ($child_z_index !== "auto") {
                $z_index = intval($child_z_index) + 1;
            } elseif ($child_style->float !== "none" || $child->is_positionned()) {
                $z_index = 1;
            }

            $stack[$z_index][] = $child;
        }

        ksort($stack);

        foreach ($stack as $by_index) {
            foreach ($by_index as $child) {
                $this->render($child);
            }
        }

        // Ends the overflow: hidden box
        if ($style->overflow === "hidden") {
            $this->_canvas->clipping_end();
        }

        if ($style->transform && is_array($style->transform)) {
            $this->_canvas->restore();
        }

        // Check for end frame callback
        $this->_check_callbacks("end_frame", $frame);
    }

    /**
     * Check for callbacks that need to be performed when a given event
     * gets triggered on a frame
     *
     * @param string $event the type of event
     * @param Frame $frame  the frame that event is triggered on
     */
    protected function _check_callbacks($event, $frame)
    {
        if (!isset($this->_callbacks)) {
            $this->_callbacks = $this->_dompdf->getCallbacks();
        }

        if (is_array($this->_callbacks) && isset($this->_callbacks[$event])) {
            $info = array(0 => $this->_canvas, "canvas" => $this->_canvas,
                1 => $frame, "frame" => $frame);
            $fs = $this->_callbacks[$event];
            foreach ($fs as $f) {
                if (is_callable($f)) {
                    if (is_array($f)) {
                        $f[0]->{$f[1]}($info);
                    } else {
                        $f($info);
                    }
                }
            }
        }
    }

    /**
     * Render a single frame
     *
     * Creates Renderer objects on demand
     *
     * @param string $type type of renderer to use
     * @param Frame $frame the frame to render
     */
    protected function _render_frame($type, $frame)
    {

        if (!isset($this->_renderers[$type])) {

            switch ($type) {
                case "block":
                    $this->_renderers[$type] = new Block($this->_dompdf);
                    break;

                case "inline":
                    $this->_renderers[$type] = new Renderer\Inline($this->_dompdf);
                    break;

                case "text":
                    $this->_renderers[$type] = new Text($this->_dompdf);
                    break;

                case "image":
                    $this->_renderers[$type] = new Image($this->_dompdf);
                    break;

                case "table-cell":
                    $this->_renderers[$type] = new TableCell($this->_dompdf);
                    break;

                case "table-row-group":
                    $this->_renderers[$type] = new TableRowGroup($this->_dompdf);
                    break;

                case "list-bullet":
                    $this->_renderers[$type] = new ListBullet($this->_dompdf);
                    break;

                case "php":
                    $this->_renderers[$type] = new PhpEvaluator($this->_canvas);
                    break;

                case "javascript":
                    $this->_renderers[$type] = new JavascriptEmbedder($this->_dompdf);
                    break;

            }
        }

        $this->_renderers[$type]->render($frame);
    }
}
