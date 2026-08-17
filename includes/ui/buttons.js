/* includes/ui/buttons.js */

// Button component with different states
function createButton(element, type) {
    const button = document.createElement('button');
    button.classList.add('btn');

    // Add type-specific classes
    switch (type) {
        case 'primary':
            button.classList.add('btn-primary');
            break;
        case 'secondary':
            button.classList.add('btn-secondary');
            break;
        case 'success':
            button.classList.add('btn-success');
            break;
        case 'danger':
            button.classList.add('btn-danger');
            break;
        default:
            button.classList.add('btn-default');
    }

    // Add event listener for click
    button.addEventListener('click', () => {
        // Emit event or call callback
        if (element.onclick) element.onclick();
    });

    return button;
}

// Example usage
// const myButton = createButton(document.getElementById('myButton'), 'primary');

---
*Component created for UI Kit Premium*