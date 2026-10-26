document.addEventListener("DOMContentLoaded", () => {
  const togglePasswordButtons = document.querySelectorAll("[data-toggle-password]");

  togglePasswordButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      const inputId = btn.getAttribute("data-toggle-password");
      const input = document.getElementById(inputId);
      if (!input) return;

      input.type = input.type === "password" ? "text" : "password";
      const showText = btn.getAttribute("data-show-text") || "Show";
      const hideText = btn.getAttribute("data-hide-text") || "Hide";
      btn.textContent = input.type === "password" ? showText : hideText;
    });
  });

  document.querySelectorAll("[data-photo-picker]").forEach((picker) => {
  const input = picker.querySelector(".photo-input");
  const addControl = picker.querySelector("[data-photo-add]");
  const preview = picker.querySelector("[data-photo-preview]");
  const errorBox = picker.querySelector("[data-photo-errors]");

  if (!input || !addControl || !preview) return;

  const allowedExtensions = new Set(["jpg", "jpeg", "png", "webp"]);
  const allowedTypes = new Set([
    "image/jpeg",
    "image/png",
    "image/webp"
  ]);

  const maximumFileSize = 5 * 1024 * 1024;
  const maximumTotalSize = 10 * 1024 * 1024;
  const maximumFiles = 10;
  const minimumDimension = 600;
  const maximumDimension = 8000;

  let selectedFiles = [];

  const showErrors = (errors) => {
    if (!errorBox) return;

    errorBox.innerHTML = "";

    if (errors.length === 0) {
      errorBox.hidden = true;
      return;
    }

    const heading = document.createElement("strong");
    heading.textContent =
      errors.length === 1
        ? "This photo could not be added:"
        : "Some photos could not be added:";

    const list = document.createElement("ul");

    errors.forEach((error) => {
      const item = document.createElement("li");
      item.textContent = error;
      list.appendChild(item);
    });

    errorBox.appendChild(heading);
    errorBox.appendChild(list);
    errorBox.hidden = false;
  };

  const syncInputFiles = () => {
    const dataTransfer = new DataTransfer();

    selectedFiles.forEach((file) => {
      dataTransfer.items.add(file);
    });

    input.files = dataTransfer.files;
  };

  const renderPreview = () => {
    preview.innerHTML = "";

    if (selectedFiles.length === 0) {
      const emptyText =
        picker.getAttribute("data-empty-text") ||
        "No new photos selected yet.";

      const emptyMessage = document.createElement("div");
      emptyMessage.className = "photo-preview-empty";
      emptyMessage.textContent = emptyText;
      preview.appendChild(emptyMessage);
      return;
    }

    selectedFiles.forEach((file, index) => {
      const item = document.createElement("div");
      item.className = "photo-preview-item";

      const img = document.createElement("img");
      img.alt = file.name;

      const imageUrl = URL.createObjectURL(file);
      img.src = imageUrl;
      img.onload = () => URL.revokeObjectURL(imageUrl);

      const removeButton = document.createElement("button");
      removeButton.type = "button";
      removeButton.className = "photo-remove-button";
      removeButton.textContent = "×";
      removeButton.setAttribute("aria-label", `Remove ${file.name}`);

      removeButton.addEventListener("click", () => {
        selectedFiles.splice(index, 1);
        syncInputFiles();
        renderPreview();
        showErrors([]);
      });

      const name = document.createElement("span");
      name.className = "photo-file-name";
      name.textContent = file.name;

      item.appendChild(img);
      item.appendChild(removeButton);
      item.appendChild(name);
      preview.appendChild(item);
    });
  };

  const readImageDimensions = (file) => {
    return new Promise((resolve, reject) => {
      const img = new Image();
      const imageUrl = URL.createObjectURL(file);

      img.onload = () => {
        const dimensions = {
          width: img.naturalWidth,
          height: img.naturalHeight
        };

        URL.revokeObjectURL(imageUrl);
        resolve(dimensions);
      };

      img.onerror = () => {
        URL.revokeObjectURL(imageUrl);
        reject(new Error("The image could not be read."));
      };

      img.src = imageUrl;
    });
  };

  const validatePhoto = async (file) => {
    const extension = file.name.includes(".")
      ? file.name.split(".").pop().toLowerCase()
      : "";

    if (
      !allowedExtensions.has(extension) ||
      (file.type && !allowedTypes.has(file.type))
    ) {
      return "Only JPG, JPEG, PNG, and WEBP photos are allowed.";
    }

    if (file.size <= 0) {
      return "The photo is empty or damaged.";
    }

    if (file.size > maximumFileSize) {
      return "The photo is larger than the 5 MB limit.";
    }

    try {
      const dimensions = await readImageDimensions(file);

      if (
        dimensions.width < minimumDimension ||
        dimensions.height < minimumDimension
      ) {
        return `The photo is ${dimensions.width} × ${dimensions.height} pixels. Listing photos must be at least 600 × 600 pixels.`;
      }

      if (
        dimensions.width > maximumDimension ||
        dimensions.height > maximumDimension
      ) {
        return `The photo is ${dimensions.width} × ${dimensions.height} pixels. Listing photos may not exceed 8000 × 8000 pixels.`;
      }
    } catch (error) {
      return "The file is not a valid or readable image.";
    }

    return "";
  };

  if (addControl.tagName.toLowerCase() !== "label") {
    addControl.addEventListener("click", () => input.click());
  }

  input.addEventListener("change", async () => {
    const newFiles = Array.from(input.files || []);
    const errors = [];

    for (const file of newFiles) {
      const alreadyAdded = selectedFiles.some((existing) => {
        return (
          existing.name === file.name &&
          existing.size === file.size &&
          existing.lastModified === file.lastModified
        );
      });

      if (alreadyAdded) {
        errors.push(`${file.name}: This photo has already been selected.`);
        continue;
      }

      if (selectedFiles.length >= maximumFiles) {
        errors.push(
          `${file.name}: A maximum of ${maximumFiles} photos may be uploaded.`
        );
        continue;
      }

      const currentTotalSize = selectedFiles.reduce(
        (total, selectedFile) => total + selectedFile.size,
        0
      );

      if (currentTotalSize + file.size > maximumTotalSize) {
        errors.push(
          `${file.name}: The combined size of the selected photos is too large. Select photos totalling no more than 10 MB.`
        );
        continue;
      }

      const validationError = await validatePhoto(file);

      if (validationError) {
        errors.push(`${file.name}: ${validationError}`);
        continue;
      }

      selectedFiles.push(file);
    }

    syncInputFiles();
    renderPreview();
    showErrors(errors);
  });

  renderPreview();
});

  const mainListingImage = document.querySelector("[data-main-listing-image]");
  document.querySelectorAll("[data-gallery-thumb]").forEach((button) => {
    button.addEventListener("click", () => {
      if (!mainListingImage) return;

      const imagePath = button.getAttribute("data-image-src");
      const imageAlt = button.getAttribute("data-image-alt") || "Listing photo";
      if (!imagePath) return;

      mainListingImage.src = imagePath;
      mainListingImage.alt = imageAlt;

      document.querySelectorAll("[data-gallery-thumb]").forEach((thumb) => thumb.classList.remove("is-active"));
      button.classList.add("is-active");
    });
  });

  const descriptionToggle = document.querySelector("[data-description-toggle]");
  const descriptionBody = document.querySelector("[data-collapsible-description]");

  if (descriptionToggle && descriptionBody) {
    const hideDescriptionToggleIfNotNeeded = () => {
      descriptionBody.classList.remove("is-expanded");
      const hasOverflow = descriptionBody.scrollHeight > descriptionBody.clientHeight + 2;
      descriptionToggle.hidden = !hasOverflow;
      descriptionToggle.setAttribute("aria-expanded", "false");
      descriptionToggle.textContent = descriptionToggle.getAttribute("data-show-more") || "Show more";
    };

    hideDescriptionToggleIfNotNeeded();
    window.addEventListener("resize", hideDescriptionToggleIfNotNeeded);

    descriptionToggle.addEventListener("click", () => {
      const isExpanded = descriptionBody.classList.toggle("is-expanded");
      const showMoreText = descriptionToggle.getAttribute("data-show-more") || "Show more";
      const showLessText = descriptionToggle.getAttribute("data-show-less") || "Show less";
      descriptionToggle.textContent = isExpanded ? showLessText : showMoreText;
      descriptionToggle.setAttribute("aria-expanded", String(isExpanded));
    });
  }


  const addressGroupIsVisible = (group) => {
    return !!(group.offsetWidth || group.offsetHeight || group.getClientRects().length);
  };

  const getAddressInputs = (group) => ({
    streetNumber: group.querySelector("[data-address-street-number]"),
    streetName: group.querySelector("[data-address-street-name]"),
    suburb: group.querySelector("[data-address-suburb]"),
    city: group.querySelector("[data-address-city]"),
    postalCode: group.querySelector("[data-address-postal-code]")
  });

  const isAddressGroupEmpty = (inputs) => {
    return Object.values(inputs).every((input) => !input || input.value.trim() === "");
  };

  const isValidAddressGroup = (group) => {
    const inputs = getAddressInputs(group);

    if (!inputs.streetNumber || !inputs.streetName || !inputs.suburb || !inputs.city || !inputs.postalCode) {
      return false;
    }

    const streetNumber = inputs.streetNumber.value.trim();
    const streetName = inputs.streetName.value.trim();
    const suburb = inputs.suburb.value.trim();
    const city = inputs.city.value.trim();
    const postalCode = inputs.postalCode.value.trim();

    return /^[0-9]+[A-Za-z0-9/-]*$/.test(streetNumber)
      && /[A-Za-z]/.test(streetName) && streetName.length >= 2
      && /[A-Za-z]/.test(suburb) && suburb.length >= 2
      && /[A-Za-z]/.test(city) && city.length >= 2
      && /^[0-9]{4}$/.test(postalCode);
  };

  const setAddressGroupRequired = (group, required) => {
    if (!group) return;

    group.setAttribute("data-address-required", required ? "true" : "false");
    group.querySelectorAll("input").forEach((input) => {
      if (input.readOnly) return;
      input.required = required;
    });
  };

  document.querySelectorAll("[data-validate-address-form]").forEach((form) => {
    form.addEventListener("submit", (event) => {
      const errorMessage = form.getAttribute("data-address-error")
        || "Please complete a valid address using street number, street name, suburb, city/town, and a 4-digit postal code.";

      for (const group of form.querySelectorAll("[data-address-group]")) {
        if (!addressGroupIsVisible(group)) continue;

        const inputs = getAddressInputs(group);
        const required = group.getAttribute("data-address-required") === "true"
          || Object.values(inputs).some((input) => input && input.required);

        if (!required && isAddressGroupEmpty(inputs)) {
          continue;
        }

        if (!isValidAddressGroup(group)) {
          event.preventDefault();
          alert(errorMessage);

          const firstInvalid = Object.values(inputs).find((input) => input && !input.readOnly && input.value.trim() === "")
            || inputs.streetNumber;
          if (firstInvalid && !firstInvalid.readOnly) firstInvalid.focus();
          return;
        }
      }
    });
  });

  document.querySelectorAll("[data-fulfilment-options]").forEach((box) => {
    const pickupOption = box.querySelector("[data-pickup-option]");
    const pickupWrap = box.querySelector("[data-pickup-address-wrap]");
    const pickupGroup = pickupWrap ? pickupWrap.querySelector("[data-address-group]") : null;

    const updatePickupVisibility = () => {
      if (!pickupOption || !pickupWrap) return;

      const enabled = pickupOption.checked;
      pickupWrap.style.display = enabled ? "" : "none";
      setAddressGroupRequired(pickupGroup, enabled);
    };

    if (pickupOption) {
      pickupOption.addEventListener("change", updatePickupVisibility);
      updatePickupVisibility();
    }
  });

  document.querySelectorAll("[data-buy-screen]").forEach((screen) => {
    const unitPrice = Number(screen.getAttribute("data-unit-price") || "0");
    const qtyInput = screen.querySelector("[data-buy-quantity]");
    const totalOutput = screen.querySelector("[data-buy-total]");
    const deliveryWrap = screen.querySelector("[data-delivery-address-wrap]");
    const pickupWrap = screen.querySelector("[data-pickup-address-wrap-buy]");
    const deliveryGroup = deliveryWrap ? deliveryWrap.querySelector("[data-address-group]") : null;
    const pickupGroup = pickupWrap ? pickupWrap.querySelector("[data-address-group]") : null;
    const saveAddressWrap = screen.querySelector("[data-save-address-wrap]");

    const formatRand = (value) => {
      return "R" + value.toLocaleString("en-ZA", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    };

    const updateTotal = () => {
      if (!qtyInput || !totalOutput) return;

      const max = Number(qtyInput.getAttribute("max") || "1");
      let qty = Number(qtyInput.value || "1");

      if (qty < 1) qty = 1;
      if (qty > max) qty = max;

      qtyInput.value = String(qty);
      totalOutput.textContent = formatRand(unitPrice * qty);
    };

    const updateMethod = () => {
      const selected = screen.querySelector("[data-buy-method]:checked");
      if (!selected) return;

      const isPickup = selected.value === "pickup";

      if (deliveryWrap) deliveryWrap.style.display = isPickup ? "none" : "";
      if (pickupWrap) pickupWrap.style.display = isPickup ? "" : "none";
      if (saveAddressWrap) saveAddressWrap.style.display = isPickup ? "none" : "";

      setAddressGroupRequired(deliveryGroup, !isPickup);
      setAddressGroupRequired(pickupGroup, false);
    };

    if (qtyInput) {
      qtyInput.addEventListener("input", updateTotal);
      qtyInput.addEventListener("change", updateTotal);
      updateTotal();
    }

    screen.querySelectorAll("[data-buy-method]").forEach((radio) => {
      radio.addEventListener("change", updateMethod);
    });

    updateMethod();
  });



  if (!window.__lekkeAdminConfirmBound) {
    window.__lekkeAdminConfirmBound = true;

    const getAdminFormControls = (form) => {
      if (!form || !form.id) return [];
      return Array.from(document.querySelectorAll("[form]")).filter((field) => (
        field.getAttribute("form") === form.id &&
        field.name &&
        field.type !== "hidden"
      ));
    };

    const adminUpdateHasChanges = (form) => {
      return getAdminFormControls(form).some((field) => (
        String(field.value) !== String(field.dataset.originalValue || "")
      ));
    };

    document.querySelectorAll("form[data-confirm]").forEach((form) => {
      form.addEventListener("submit", (event) => {
        const actionInput = form.querySelector('input[name="admin_action"]');
        const action = actionInput ? actionInput.value : "";

        if (action === "update" && !adminUpdateHasChanges(form)) {
          event.preventDefault();
          alert("No changes detected. Nothing was updated.");
          return;
        }

        const message = form.getAttribute("data-confirm") || "Are you sure?";
        if (!window.confirm(message)) {
          event.preventDefault();
        }
      });
    });
  }


  document.querySelectorAll("[data-live-chat]").forEach((chatRoot) => {
    const chatId = Number(chatRoot.getAttribute("data-chat-id") || "0");
    const currentUserId = Number(chatRoot.getAttribute("data-current-user-id") || "0");
    const messagesBox = chatRoot.querySelector("[data-chat-messages]");
    const form = chatRoot.querySelector("[data-chat-send]");
    let lastMessageId = Number(chatRoot.getAttribute("data-last-message-id") || "0");
    let isFetching = false;

    if (!chatId || !messagesBox) return;

    const scrollToBottom = () => {
      messagesBox.scrollTop = messagesBox.scrollHeight;
    };

    const lastDayLabel = () => {
      const labels = messagesBox.querySelectorAll(".chat-day-separator");
      if (!labels.length) return "";
      return labels[labels.length - 1].getAttribute("data-day-label") || labels[labels.length - 1].textContent.trim();
    };

    const appendDayIfNeeded = (dayLabel) => {
      if (!dayLabel || lastDayLabel() === dayLabel) return;

      const day = document.createElement("div");
      day.className = "chat-day-separator";
      day.setAttribute("data-day-label", dayLabel);
      day.textContent = dayLabel;
      messagesBox.appendChild(day);
    };

    const appendMessage = (message) => {
      if (!message || !message.id) return;
      if (Number(message.chat_id) !== chatId) return;
      if (messagesBox.querySelector(`[data-message-id="${message.id}"]`)) return;

      const emptyState = messagesBox.querySelector(".chat-empty-state");
      if (emptyState) emptyState.remove();

      appendDayIfNeeded(message.day_label);

      const wrapper = document.createElement("div");
      wrapper.className = "chat-message";
      if (Number(message.sender_id) === currentUserId) {
        wrapper.classList.add("chat-message--own");
      }
      wrapper.setAttribute("data-message-id", String(message.id));

      const bubble = document.createElement("div");
      bubble.className = "chat-bubble";

      const text = document.createElement("p");
      text.textContent = message.message_text || "";

      const time = document.createElement("span");
      time.textContent = message.time_label || "";

      bubble.appendChild(text);
      bubble.appendChild(time);
      wrapper.appendChild(bubble);
      messagesBox.appendChild(wrapper);

      lastMessageId = Math.max(lastMessageId, Number(message.id));
    };

    const fetchMessages = async () => {
      if (isFetching) return;
      isFetching = true;

      try {
        const response = await fetch(`api/messages_fetch.php?chat_id=${encodeURIComponent(chatId)}&after_id=${encodeURIComponent(lastMessageId)}`, {
          cache: "no-store"
        });
        const data = await response.json();

        if (data.success && Array.isArray(data.messages) && data.messages.length) {
          data.messages.forEach(appendMessage);
          scrollToBottom();
        }
      } catch (error) {
        // Temporary connection errors should not break the chat screen.
      } finally {
        isFetching = false;
      }
    };

    if (form) {
      form.addEventListener("submit", async (event) => {
        event.preventDefault();

        const textarea = form.querySelector("textarea[name='message_text']");
        if (!textarea || textarea.value.trim() === "") return;

        const formData = new FormData(form);
        const submitButton = form.querySelector("button[type='submit']");
        if (submitButton) submitButton.disabled = true;

        try {
          const response = await fetch("api/messages_send.php", {
            method: "POST",
            body: formData
          });
          const data = await response.json();

          if (!data.success) {
            alert(data.message || "Message could not be sent.");
            return;
          }

          textarea.value = "";
          appendMessage(data.message);
          scrollToBottom();
        } catch (error) {
          alert("Message could not be sent. Please try again.");
        } finally {
          if (submitButton) submitButton.disabled = false;
          textarea.focus();
        }
      });
    }

    scrollToBottom();
    fetchMessages();
    setInterval(fetchMessages, 3000);
  });


  document.querySelectorAll("[data-review-toggle]").forEach((button) => {
    button.addEventListener("click", () => {
      const card = button.closest(".reviews-card");
      if (!card) return;

      const isExpanded = card.classList.toggle("is-expanded");
      const showMoreText = button.getAttribute("data-show-more") || "Read more reviews";
      const showLessText = button.getAttribute("data-show-less") || "Show fewer reviews";
      button.textContent = isExpanded ? showLessText : showMoreText;
    });
  });


  document.querySelectorAll("[data-kyc-camera]").forEach((cameraBox) => {
    const video = cameraBox.querySelector("[data-kyc-video]");
    const canvas = cameraBox.querySelector("[data-kyc-canvas]");
    const preview = cameraBox.querySelector("[data-kyc-preview]");
    const captured = cameraBox.querySelector("[data-kyc-captured]");
    const startButton = cameraBox.querySelector("[data-kyc-start]");
    const captureButton = cameraBox.querySelector("[data-kyc-capture]");
    let stream = null;

    const stopStream = () => {
      if (!stream) return;
      stream.getTracks().forEach((track) => track.stop());
      stream = null;
    };

    if (startButton && video && navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
      startButton.addEventListener("click", async () => {
        try {
          stopStream();
          stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: "user" },
            audio: false
          });
          video.srcObject = stream;
          video.hidden = false;
        } catch (error) {
          alert("Camera could not be started. You can upload a selfie image instead.");
        }
      });
    }

    if (captureButton && video && canvas && preview && captured) {
      captureButton.addEventListener("click", () => {
        if (!video.videoWidth || !video.videoHeight) {
          alert("Please start the camera before capturing a selfie.");
          return;
        }

        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;

        const context = canvas.getContext("2d");
        if (!context) return;

        context.drawImage(video, 0, 0, canvas.width, canvas.height);
        const dataUrl = canvas.toDataURL("image/jpeg", 0.9);
        captured.value = dataUrl;
        preview.src = dataUrl;
        preview.hidden = false;
        stopStream();
      });
    }

    window.addEventListener("beforeunload", stopStream);
  });

});
