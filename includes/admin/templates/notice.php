<?php
	$message = empty( $message ) ? '' : $message;
	$class = empty( $class ) ? 'wds-notice-warning' : $class;
?>
<div class="wds-notice <?php echo esc_attr( $class ); ?>">
	<p><?php echo wp_kses( $message, 'post' ); ?></p>
</div>