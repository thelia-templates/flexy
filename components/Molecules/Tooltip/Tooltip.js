const TooltipFunction = () => {
  const tooltip = document.querySelector(".Tooltip-text");
  const close = document.querySelector('.Tooltip-close');

  if (icon && tooltip && close) {
    icon.addEventListener('click', function () {
      tooltip.classList.add('Tooltip-text--show');
    });

    close.addEventListener('click', function () {
      tooltip.classList.remove('Tooltip-text--show');
    });
  }
}

export default TooltipFunction;
