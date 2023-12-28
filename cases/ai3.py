import cv2
import os
import numpy as np
import torch
from modelscope import snapshot_download
from PIL import Image
import onnxruntime

def softmax(x):
    x -= np.max(x, axis=0, keepdims=True)
    x = np.exp(x) / np.sum(np.exp(x), axis=0, keepdims=True)
    return x

def get_rot(image, ort_session):
    img_cv = cv2.cvtColor(np.asarray(image), cv2.COLOR_RGB2BGR)
    img_clone = img_cv.copy()
    img_np = cv2.resize(img_cv, (224, 224))
    img_np = img_np.astype(np.float32)
    mean = np.array([103.53, 116.28, 123.675], dtype=np.float32).reshape((1, 1, 3))
    norm = np.array([0.01742919, 0.017507, 0.01712475], dtype=np.float32).reshape((1, 1, 3))
    img_np = (img_np - mean) * norm
    img_tensor = torch.from_numpy(img_np)
    img_tensor = img_tensor.unsqueeze(0)
    img_nchw = img_tensor.permute(0, 3, 1, 2)
    ort_inputs = {ort_session.get_inputs()[0].name: img_nchw.numpy()}
    outputs = ort_session.run(None, ort_inputs)
    logits = outputs[0].reshape((-1,))
    probs = softmax(logits)
    rot_idx = np.argmax(probs)
    if rot_idx == 1:
        print('rot 90')
        img_clone = cv2.transpose(img_clone)
        img_clone = np.flip(img_clone, 1)
        return Image.fromarray(cv2.cvtColor(img_clone, cv2.COLOR_BGR2RGB))
    elif rot_idx == 2:
        print('rot 180')
        img_clone = cv2.flip(img_clone, -1)
        return Image.fromarray(cv2.cvtColor(img_clone, cv2.COLOR_BGR2RGB))
    elif rot_idx == 3:
        print('rot 270')
        img_clone = cv2.transpose(img_clone)
        img_clone = np.flip(img_clone, 0)
        return Image.fromarray(cv2.cvtColor(img_clone, cv2.COLOR_BGR2RGB))
    else:
        return image

model_dir = snapshot_download('Cherrytest/rot_bgr', revision='v1.0.0')
model_path = os.path.join(model_dir, 'rot_bgr.onnx')
ort_session = onnxruntime.InferenceSession(model_path)
img_path = 'path_of_your_image'
image = Image.open(img_path)
image = image.convert('RGB')
image = get_rot(image, ort_session)
out_path = 'path_to_save_image'
image.save(out_path)
