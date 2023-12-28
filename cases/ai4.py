import tempfile
from modelscope.msdatasets import MsDataset
from modelscope.metainfo import Trainers
from modelscope.trainers import build_trainer
from modelscope.utils.constant import DownloadMode
from modelscope.utils.hub import snapshot_download


train_dataset = MsDataset(
    MsDataset.load(
        "coco_2014_caption",
        namespace="modelscope",
        split="train[:100]",
        download_mode=DownloadMode.REUSE_DATASET_IF_EXISTS).remap_columns({
        'image': 'image',
        'caption': 'text'
    }))
test_dataset = MsDataset(
    MsDataset.load(
        "coco_2014_caption",
        namespace="modelscope",
        split="validation[:20]",
        download_mode=DownloadMode.REUSE_DATASET_IF_EXISTS).remap_columns({
        'image': 'image',
        'caption': 'text'
    }))


def cfg_modify_fn(cfg):
    cfg.train.hooks = [{
        'type': 'CheckpointHook',
        'interval': 2
    }, {
        'type': 'TextLoggerHook',
        'interval': 1
    }, {
        'type': 'IterTimerHook'
    }]
    cfg.train.max_epochs=2
    return cfg

pretrained_model = 'damo/ofa_pretrain_base_zh'
pretrain_path = snapshot_download(pretrained_model, revision='v1.0.2')

args = dict(
    model=pretrain_path,
    train_dataset=train_dataset,
    eval_dataset=test_dataset,
    cfg_modify_fn=cfg_modify_fn,
    work_dir = tempfile.TemporaryDirectory().name)
trainer = build_trainer(name=Trainers.ofa, default_args=args)
trainer.train()
